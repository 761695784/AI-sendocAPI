<?php

namespace App\Services\Collections;

use App\Models\CollectionRequest;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\FoundDocuments\FoundDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collecte des documents publiés par les citoyens :
 * le trouveur n'a pas à se déplacer de lui-même, la structure la plus proche
 * l'appelle et organise le dépôt ou le passage d'un agent.
 */
class CollectionService
{
    public function __construct(
        private NearestStructureFinder $finder,
        private FoundDocumentService $foundDocuments,
    ) {}

    /** Ouvre une collecte (le trouveur a accepté d'être contacté). */
    public function open(Document $document, User $finderUser, string $phone): ?CollectionRequest
    {
        if ($document->found_channel === Document::CHANNEL_COUNTER
            || $document->isInCustody()
            // Propriétaire déjà identifié (matched) : le document reste à récupérer.
            || ! in_array($document->lifecycle_status, [Document::LIFECYCLE_PUBLISHED, Document::LIFECYCLE_MATCHED], true)
            || $document->openCollection()) {
            return null;
        }

        $request = DB::transaction(function () use ($document, $finderUser, $phone) {
            $request = (new CollectionRequest([
                'document_id'    => $document->id,
                'status'         => CollectionRequest::STATUS_UNASSIGNED,
                'finder_user_id' => $finderUser->id,
                'finder_name'    => trim($finderUser->FirstName . ' ' . $finderUser->LastName),
            ]))->setFinderPhone($phone);
            $request->save();

            StatusTransition::record($request, null, CollectionRequest::STATUS_UNASSIGNED, 'Publication avec accord de contact', $finderUser);

            // Déjà une correspondance probable ? Le dossier est prioritaire.
            if ($document->matches()->whereIn('status', ['pending_review', 'confirmed'])->exists()) {
                $request->owner_identified_at = now();
                $request->save();
            }

            return $request;
        });

        return $this->assign($request, null);
    }

    /** Affecte à la structure la plus proche qui n'a pas encore refusé. */
    public function assign(CollectionRequest $request, ?User $actor, string $context = 'Affectation automatique'): CollectionRequest
    {
        $document = $request->document;
        $found    = $this->finder->find(
            $document->found_latitude,
            $document->found_longitude,
            $document->territory_id,
            $request->declined_organization_ids ?? [],
        );

        $from = $request->status;

        if (! $found) {
            $request->fill(['organization_id' => null, 'status' => CollectionRequest::STATUS_UNASSIGNED,
                'assignment_reason' => null, 'distance_km' => null, 'assigned_at' => null])->save();
            if ($from !== CollectionRequest::STATUS_UNASSIGNED) {
                StatusTransition::record($request, $from, CollectionRequest::STATUS_UNASSIGNED, 'Aucune structure disponible', $actor);
            }

            return $request->fresh();
        }

        $request->fill([
            'organization_id'   => $found['organization']->id,
            'status'            => CollectionRequest::STATUS_ASSIGNED,
            'assignment_reason' => $found['reason'],
            'distance_km'       => $found['distance_km'],
            'assigned_at'       => now(),
        ])->save();

        StatusTransition::record($request, $from, CollectionRequest::STATUS_ASSIGNED,
            $context . ' : ' . $found['organization']->name . $this->distanceLabel($found), $actor, $found['organization']->id);

        return $request->fresh();
    }

    /** La structure ne peut pas s'en charger : on passe à la suivante la plus proche. */
    public function decline(CollectionRequest $request, User $agent, string $reason): CollectionRequest
    {
        return DB::transaction(function () use ($request, $agent, $reason) {
            $declined   = $request->declined_organization_ids ?? [];
            $declined[] = $request->organization_id;
            $request->declined_organization_ids = array_values(array_unique($declined));
            $request->contact_attempts = 0;
            $request->last_contact_outcome = null;
            $request->save();

            activity()->causedBy($agent)->performedOn($request)
                ->withProperties(['reason' => $reason])->log('Collecte refusée par la structure');

            return $this->assign($request, $agent, 'Réaffectation après refus (« ' . $reason . ' »)');
        });
    }

    /** Transfert vers une structure choisie (plus proche, plus disponible…). */
    public function transfer(CollectionRequest $request, User $agent, Organization $target, string $reason): CollectionRequest
    {
        $target->loadMissing('type');
        if (! $target->is_active || ! $target->accepts_collections || $target->type?->level !== OrganizationType::LEVEL_STRUCTURE) {
            throw ValidationException::withMessages(['organization_uuid' => 'Cette structure ne peut pas recevoir de collecte.']);
        }
        if ($target->id === $request->organization_id) {
            throw ValidationException::withMessages(['organization_uuid' => 'Le dossier est déjà affecté à cette structure.']);
        }

        $from = $request->status;
        $request->fill([
            'organization_id'      => $target->id,
            'status'               => CollectionRequest::STATUS_ASSIGNED,
            'assignment_reason'    => 'transfer',
            'distance_km'          => $this->distanceTo($request->document, $target),
            'assigned_at'          => now(),
            'contact_attempts'     => 0,
            'last_contact_outcome' => null,
            'method'               => null,
            'scheduled_at'         => null,
            'pickup_agent_id'      => null,
        ])->save();

        StatusTransition::record($request, $from, CollectionRequest::STATUS_ASSIGNED, 'Transférée à ' . $target->name . ' : ' . $reason, $agent, $target->id);

        return $request->fresh();
    }

    /** Trace chaque appel au trouveur. */
    public function recordContact(CollectionRequest $request, User $agent, string $outcome, ?string $note = null): CollectionRequest
    {
        $from = $request->status;
        $request->contact_attempts     = $request->contact_attempts + 1;
        $request->last_contact_at      = now();
        $request->last_contact_outcome = $outcome;

        if ($outcome === CollectionRequest::OUTCOME_REACHED && $from === CollectionRequest::STATUS_ASSIGNED) {
            $request->status = CollectionRequest::STATUS_CONTACTED;
        }
        $request->save();

        $labels = [
            CollectionRequest::OUTCOME_REACHED      => 'Trouveur joint',
            CollectionRequest::OUTCOME_NO_ANSWER    => 'Pas de réponse',
            CollectionRequest::OUTCOME_WRONG_NUMBER => 'Numéro erroné',
        ];
        StatusTransition::record($request, $from, $request->status,
            'Appel n°' . $request->contact_attempts . ' : ' . $labels[$outcome] . ($note ? ' — ' . $note : ''), $agent, $request->organization_id);

        return $request->fresh();
    }

    /** Dépôt par le trouveur ou passage d'un agent. */
    public function plan(CollectionRequest $request, User $agent, string $method, ?string $scheduledAt, ?string $meetingPlace, ?User $pickupAgent): CollectionRequest
    {
        if ($method === CollectionRequest::METHOD_AGENT_PICKUP && ! $pickupAgent) {
            $pickupAgent = $agent;
        }

        $from = $request->status;
        $request->fill([
            'status'          => CollectionRequest::STATUS_PLANNED,
            'method'          => $method,
            'scheduled_at'    => $scheduledAt,
            'meeting_place'   => $meetingPlace,
            'pickup_agent_id' => $method === CollectionRequest::METHOD_AGENT_PICKUP ? $pickupAgent?->id : null,
        ])->save();

        $label = $method === CollectionRequest::METHOD_DROP_OFF
            ? 'Le trouveur apporte le document'
            : 'Un agent récupère le document' . ($meetingPlace ? ' (' . $meetingPlace . ')' : '');
        StatusTransition::record($request, $from, CollectionRequest::STATUS_PLANNED, $label, $agent, $request->organization_id);

        return $request->fresh();
    }

    /** Le document est entre les mains de la structure. */
    public function markCollected(CollectionRequest $request, User $agent): CollectionRequest
    {
        $structure = $request->organization;
        $this->foundDocuments->takeCustody($request->document, $agent, $structure); // clôture la collecte

        return $request->fresh();
    }

    public function cancel(CollectionRequest $request, ?User $actor, string $reason): CollectionRequest
    {
        $from = $request->status;
        $request->fill(['status' => CollectionRequest::STATUS_CANCELLED, 'closed_reason' => $reason])->save();
        StatusTransition::record($request, $from, CollectionRequest::STATUS_CANCELLED, $reason, $actor, $request->organization_id);

        return $request->fresh();
    }

    /** Document restitué, supprimé… : la collecte ouverte n'a plus lieu d'être. */
    public function cancelForDocument(Document $document, ?User $actor, string $reason): void
    {
        $document->collectionRequests()->open()->get()->each(fn ($r) => $this->cancel($r, $actor, $reason));
    }

    /** Propriétaire identifié : la structure est invitée à traiter le dossier en priorité. */
    public function flagOwnerIdentified(Document $document): void
    {
        $document->collectionRequests()->open()->whereNull('owner_identified_at')->update(['owner_identified_at' => now()]);
    }

    private function distanceTo(Document $document, Organization $org): ?float
    {
        if ($document->found_latitude === null || $org->latitude === null) {
            return null;
        }

        return NearestStructureFinder::distanceKm($document->found_latitude, $document->found_longitude, $org->latitude, $org->longitude);
    }

    private function distanceLabel(array $found): string
    {
        return $found['distance_km'] !== null ? ' (' . number_format($found['distance_km'], 1, ',', ' ') . ' km)' : '';
    }
}

<?php

namespace App\Services\Restitution;

use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\RestitutionRequest;
use App\Models\StatusTransition;
use App\Models\User;
use App\Notifications\RestitutionRequestNotification;
use App\Notifications\RestitutionUpdateNotification;
use Illuminate\Support\Facades\DB;

/**
 * Restitution directe propriétaire ↔ trouveur, en double accord :
 *  1. seul le propriétaire CONFIRMÉ par DocTrack peut demander ;
 *  2. le trouveur est prévenu (SMS + email) SANS le numéro du demandeur ;
 *  3. s'il accepte, chacun reçoit le numéro de l'autre ;
 *  4. s'il refuse ou ne répond pas à temps, la structure la plus proche prend le relais.
 */
class DirectRestitutionService
{
    public function __construct(private \App\Services\Notifications\Notifier $notifier) {}

    /** Correspondance confirmée entre ce document et une déclaration de ce citoyen. */
    public function confirmedMatchFor(Document $document, User $requester): ?DocumentMatch
    {
        return $document->matches()
            ->where('status', DocumentMatch::STATUS_CONFIRMED)
            ->whereHas('declaration', fn ($q) => $q->where('user_id', $requester->id))
            ->first();
    }

    public function activeRequest(Document $document): ?RestitutionRequest
    {
        return $document->restitutionRequests()->active()->latest('id')->first();
    }

    public function request(Document $document, User $requester, DocumentMatch $match): RestitutionRequest
    {
        $request = DB::transaction(function () use ($document, $requester, $match) {
            $request = RestitutionRequest::create([
                'document_id'       => $document->id,
                'document_match_id' => $match->id,
                'requester_user_id' => $requester->id,
                'finder_user_id'    => $document->user_id,
                'status'            => RestitutionRequest::STATUS_PENDING,
                'expires_at'        => now()->addHours(config('doctrack.restitution.response_hours', 48)),
            ]);
            StatusTransition::record($request, null, RestitutionRequest::STATUS_PENDING, 'Demande du propriétaire confirmé', $requester);

            $this->noteOnCollection($document, 'Le propriétaire confirmé a demandé une remise directe au trouveur', $requester);

            activity()->causedBy($requester)->performedOn($document)
                ->withProperties(['request' => $request->uuid, 'match' => $match->uuid])
                ->log('Demande de restitution envoyée');

            return $request;
        });

        $finder  = $document->user;
        $context = ['subject' => $request, 'recipient' => $finder];
        $this->notifier->mail('restitution.requested', $finder, new RestitutionRequestNotification($requester, $document, true, $request), $context);
        $this->notifier->sms('restitution.requested', $this->phoneOf($finder, $document), [
            'owner_name' => $document->OwnerFirstName . ' ' . $document->OwnerLastName,
            'url'        => $this->link($request),
        ], $context);

        return $request;
    }

    /** Le trouveur accepte : échange des numéros. */
    public function accept(RestitutionRequest $request, User $finder): RestitutionRequest
    {
        $request = DB::transaction(function () use ($request, $finder) {
            $request->fill([
                'status'            => RestitutionRequest::STATUS_ACCEPTED,
                'responded_at'      => now(),
                'contact_shared_at' => now(),
            ])->save();
            StatusTransition::record($request, RestitutionRequest::STATUS_PENDING, RestitutionRequest::STATUS_ACCEPTED, 'Le trouveur accepte la mise en contact', $finder);
            $this->noteOnCollection($request->document, 'Le trouveur a accepté une remise directe au propriétaire', $finder);

            return $request->fresh(['document', 'requester', 'finder']);
        });

        $doc        = $request->document;
        $owner      = $request->requester;
        $ownerPhone = $owner->Phone;
        $finderPhone = $this->phoneOf($request->finder, $doc);
        $what       = 'le document au nom de ' . $doc->OwnerFirstName . ' ' . $doc->OwnerLastName;

        $params = [
            'owner_name'       => $doc->OwnerFirstName . ' ' . $doc->OwnerLastName,
            'owner_first_name' => $owner->FirstName,
            'owner_phone'      => $ownerPhone,
            'finder_name'      => $request->finder->FirstName,
            'finder_phone'     => $finderPhone,
        ];
        $this->notifier->sms('restitution.accepted.owner', $ownerPhone, $params, ['subject' => $request, 'recipient' => $owner]);
        $this->notifier->sms('restitution.accepted.finder', $finderPhone, $params, ['subject' => $request, 'recipient' => $request->finder]);

        $this->notifier->mail('restitution.accepted', $owner, new RestitutionUpdateNotification($request, RestitutionUpdateNotification::CONTACT_FOR_OWNER, $finderPhone), ['subject' => $request]);
        $this->notifier->mail('restitution.accepted', $request->finder, new RestitutionUpdateNotification($request, RestitutionUpdateNotification::CONTACT_FOR_FINDER, $ownerPhone), ['subject' => $request]);

        return $request;
    }

    /** Refus du trouveur (ou délai dépassé) : la structure la plus proche prend le relais. */
    public function decline(RestitutionRequest $request, ?User $finder, ?string $reason, bool $expired = false): RestitutionRequest
    {
        $to = $expired ? RestitutionRequest::STATUS_EXPIRED : RestitutionRequest::STATUS_DECLINED;

        $request->fill(['status' => $to, 'responded_at' => $expired ? null : now(), 'decline_reason' => $reason])->save();
        StatusTransition::record($request, RestitutionRequest::STATUS_PENDING, $to,
            $expired ? 'Pas de réponse du trouveur dans le délai' : 'Refus du trouveur' . ($reason ? ' : ' . $reason : ''), $finder);

        $doc        = $request->document;
        $collection = $doc->openCollection();
        $this->noteOnCollection($doc, 'Remise directe impossible (' . ($expired ? 'sans réponse' : 'refus') . ') : collecte à traiter en priorité', $finder);
        $collection?->forceFill(['owner_identified_at' => $collection->owner_identified_at ?? now()])->save();

        $next = $collection?->organization
            ? $collection->organization->name . ' va récupérer le document auprès du trouveur ; vous serez prévenu.'
            : 'Vous pouvez signaler la situation à la structure de police ou de gendarmerie la plus proche avec la référence ' . $doc->reference . '.';

        $event = $expired ? 'restitution.expired' : 'restitution.declined';
        $this->notifier->sms($event, $request->requester->Phone, ['next' => $next], ['subject' => $request, 'recipient' => $request->requester]);
        $this->notifier->mail($event, $request->requester, new RestitutionUpdateNotification($request, $expired ? RestitutionUpdateNotification::EXPIRED : RestitutionUpdateNotification::DECLINED, $next), ['subject' => $request]);

        return $request->fresh();
    }

    public function cancel(RestitutionRequest $request, ?User $actor, string $reason): void
    {
        $from = $request->status;
        $request->fill(['status' => RestitutionRequest::STATUS_CANCELLED, 'decline_reason' => $reason])->save();
        StatusTransition::record($request, $from, RestitutionRequest::STATUS_CANCELLED, $reason, $actor);
    }

    /** Remise déclarée par le trouveur : la demande est close. */
    public function completeForDocument(Document $document, ?User $actor): void
    {
        $document->restitutionRequests()->active()->get()->each(function (RestitutionRequest $r) use ($actor) {
            $from = $r->status;
            $r->fill(['status' => RestitutionRequest::STATUS_COMPLETED, 'completed_at' => now()])->save();
            StatusTransition::record($r, $from, RestitutionRequest::STATUS_COMPLETED, 'Remise déclarée par le trouveur', $actor);
        });
    }

    public function cancelForDocument(Document $document, ?User $actor, string $reason): void
    {
        $document->restitutionRequests()->active()->get()->each(fn ($r) => $this->cancel($r, $actor, $reason));
    }

    /** Demandes sans réponse dans le délai. */
    public function expireOverdue(): int
    {
        $overdue = RestitutionRequest::where('status', RestitutionRequest::STATUS_PENDING)
            ->where('expires_at', '<', now())->get();
        $overdue->each(fn ($r) => $this->decline($r, null, null, expired: true));

        return $overdue->count();
    }

    public function link(RestitutionRequest $request): string
    {
        return rtrim(config('doctrack.frontend_url'), '/') . '/restitutions/' . $request->uuid;
    }

    /** Numéro du trouveur : celui du compte, sinon celui donné pour la collecte. */
    private function phoneOf(User $finder, Document $document): ?string
    {
        return $finder->Phone ?: $document->collectionRequests()->latest('id')->first()?->finderPhone();
    }

    private function noteOnCollection(Document $document, string $reason, ?User $actor): void
    {
        if ($collection = $document->openCollection()) {
            StatusTransition::record($collection, $collection->status, $collection->status, $reason, $actor, $collection->organization_id);

            if ($collection->organization) {
                $this->notifier->inbox('collection.direct_restitution', $collection->organization,
                    ['reference' => $document->reference, 'detail' => $reason . '.'],
                    ['subject' => $collection], 'collections/' . $collection->uuid);
            }
        }
    }
}

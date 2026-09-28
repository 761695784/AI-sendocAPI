<?php

namespace App\Services\FoundDocuments;

use App\Jobs\MatchDocumentJob;
use App\Models\CitizenProfile;
use App\Models\CollectionRequest;
use App\Models\Document;
use App\Models\Organization;
use App\Models\StatusTransition;
use App\Models\Territory;
use App\Models\User;
use App\Services\Declarations\CitizenProfileService;
use App\Support\SensitiveData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Documents trouvés confiés à une structure (garde physique).
 */
class FoundDocumentService
{
    public function __construct(private CitizenProfileService $citizens) {}

    /**
     * Un trouveur (ou une patrouille) dépose une pièce au guichet.
     */
    public function depositAtCounter(User $agent, Organization $structure, array $data, ?UploadedFile $image = null): Document
    {
        $document = DB::transaction(function () use ($agent, $structure, $data) {
            $finder = null;
            if (! empty($data['finder'])) {
                $finder = $this->citizens->findDuplicate($data['finder']['phone'], $data['finder']['last_name'])
                    ?? $this->citizens->createAtCounter($data['finder'], $agent, $structure);
            }

            $normalized = SensitiveData::normalizeDocNumber($data['doc_number'] ?? null);

            $document = Document::create([
                'found_channel'             => Document::CHANNEL_COUNTER,
                'lifecycle_status'          => Document::LIFECYCLE_IN_CUSTODY,
                'statut'                    => 'non récupéré',
                'document_type_id'          => $data['document_type_id'],
                'OwnerFirstName'            => $data['first_name'],
                'OwnerLastName'             => $data['last_name'],
                'birth_date_in_doc'         => $data['birth_date'] ?? null,
                // Numéro jamais en clair : chiffré + haché uniquement.
                'doc_number_encrypted'      => $normalized,
                'doc_number_hash'           => SensitiveData::hash($normalized),
                'doc_number_last4'          => $normalized ? substr($normalized, -4) : null,
                'found_at'                  => $data['found_at'] ?? now()->toDateString(),
                'found_place'               => $data['found_place'] ?? null,
                'Location'                  => $data['found_place'] ?? $structure->name,
                'territory_id'              => ! empty($data['territory_code'])
                    ? Territory::where('code', $data['territory_code'])->value('id')
                    : $structure->territory_id,
                'custody_organization_id'   => $structure->id,
                'custody_since'             => now(),
                'deposited_by_user_id'      => $agent->id,
                'finder_citizen_profile_id' => $finder?->id,
                // "Publicateur" V1 = l'agent qui a enregistré le dépôt.
                'user_id'                   => $agent->id,
            ]);

            StatusTransition::record($document, null, Document::LIFECYCLE_IN_CUSTODY, 'Déposé au guichet', $agent, $structure->id);

            activity()->causedBy($agent)->performedOn($document)
                ->withProperties(['reference' => $document->reference, 'organization' => $structure->uuid])
                ->log('Document trouvé déposé en structure');

            return $document;
        });

        if ($image) {
            $document->addMedia($image)->toMediaCollection('document_image');
        }

        dispatch(new MatchDocumentJob($document->id));

        return $document->fresh(['documentType', 'custodyOrganization', 'depositedBy', 'finder', 'matches']);
    }

    /**
     * Le trouveur apporte à la structure un document qu'il avait publié en ligne.
     */
    public function takeCustody(Document $document, User $agent, Organization $structure): Document
    {
        DB::transaction(function () use ($document, $agent, $structure) {
            $document->forceFill([
                'custody_organization_id' => $structure->id,
                'custody_since'           => now(),
                'deposited_by_user_id'    => $agent->id,
            ])->save();

            if ($document->lifecycle_status === Document::LIFECYCLE_PUBLISHED) {
                $document->moveTo(Document::LIFECYCLE_IN_CUSTODY, 'Remis à la structure par le trouveur', $agent);
            } else {
                StatusTransition::record($document, $document->lifecycle_status, $document->lifecycle_status, 'Pris en garde par ' . $structure->name, $agent, $structure->id);
            }

            // Les correspondances ouvertes sont désormais revues par cette structure.
            $document->matches()->whereNull('organization_id')->update(['organization_id' => $structure->id]);

            // Collecte en cours : le document est récupéré (par cette structure ou une autre).
            $document->collectionRequests()->open()->get()->each(function (CollectionRequest $c) use ($agent, $structure) {
                $from = $c->status;
                $c->fill([
                    'status'          => CollectionRequest::STATUS_COLLECTED,
                    'organization_id' => $structure->id,
                    'collected_at'    => now(),
                    'collected_by'    => $agent->id,
                ])->save();
                StatusTransition::record($c, $from, CollectionRequest::STATUS_COLLECTED, 'Document reçu par ' . $structure->name, $agent, $structure->id);
            });

            // Le trouveur ne l'a plus : une éventuelle remise directe n'a plus d'objet.
            app(\App\Services\Restitution\DirectRestitutionService::class)
                ->cancelForDocument($document, $agent, 'Document remis à ' . $structure->name);

            activity()->causedBy($agent)->performedOn($document)
                ->withProperties(['reference' => $document->reference, 'organization' => $structure->uuid])
                ->log('Document pris en garde par une structure');
        });

        // Propriétaire déjà prévenu qu'il pouvait s'adresser au trouveur : on lui dit où est désormais son document.
        $document->matches()->where('status', \App\Models\DocumentMatch::STATUS_CONFIRMED)->whereNotNull('notified_at')
            ->with(['declaration.user', 'declaration.citizenProfile'])->get()
            ->each(fn ($m) => \App\Jobs\SendSmsJob::dispatch(
                $m->declaration?->user?->Phone ?: $m->declaration?->citizenProfile?->phone(),
                'DocTrack : votre document au nom de ' . $document->OwnerFirstName . ' ' . $document->OwnerLastName
                . ' est maintenant conservé par ' . $structure->name . ($structure->phone ? ' (tél. ' . $structure->phone . ')' : '')
                . '. Présentez-vous avec une pièce justificative. Réf. ' . $document->reference . '.'
            ));

        dispatch(new MatchDocumentJob($document->id));

        return $document->fresh(['documentType', 'custodyOrganization', 'depositedBy', 'matches']);
    }
}

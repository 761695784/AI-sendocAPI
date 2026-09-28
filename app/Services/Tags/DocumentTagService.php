<?php

namespace App\Services\Tags;

use App\Jobs\MatchDocumentJob;
use App\Models\Charge;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\TagScan;
use App\Models\Territory;
use App\Models\User;
use App\Services\Billing\BillingManager;
use App\Services\Collections\CollectionService;
use App\Services\Notifications\Notifier;
use App\Support\SensitiveData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QR code v2 : un QR par document.
 *  - le premier QR d'un type de document est gratuit ; un renouvellement est facturé (qr_renewal) ;
 *  - le trouveur qui scanne indique où il l'a trouvé → le propriétaire est prévenu,
 *    et, s'il l'accepte, la structure la plus proche ouvre une collecte.
 */
class DocumentTagService
{
    public function __construct(
        private BillingManager $billing,
        private CollectionService $collections,
        private Notifier $notifier,
    ) {}

    public function create(User $owner, int $documentTypeId, ?string $label, ?string $docNumber): DocumentTag
    {
        if (DocumentTag::where('user_id', $owner->id)->where('document_type_id', $documentTypeId)
            ->whereIn('status', [DocumentTag::STATUS_ACTIVE, DocumentTag::STATUS_PENDING_PAYMENT])->exists()) {
            throw ValidationException::withMessages(['document_type_id' => 'Vous avez déjà un QR pour ce document : utilisez « renouveler ».']);
        }

        $normalized = SensitiveData::normalizeDocNumber($docNumber);

        $tag = DocumentTag::create([
            'user_id'          => $owner->id,
            'document_type_id' => $documentTypeId,
            'label'            => $label,
            'doc_number_last4' => $normalized ? substr($normalized, -4) : null,
            'status'           => DocumentTag::STATUS_ACTIVE,
            'activated_at'     => now(),
        ]);
        activity()->causedBy($owner)->performedOn($tag)->log('QR de document créé');

        return $tag;
    }

    /** Nouveau QR (ancien abîmé ou perdu) : facturé ; l'ancien est désactivé au règlement. */
    public function renew(DocumentTag $old, User $owner): array
    {
        if (! in_array($old->status, [DocumentTag::STATUS_ACTIVE, DocumentTag::STATUS_REVOKED], true)) {
            throw ValidationException::withMessages(['tag' => 'Ce QR ne peut pas être renouvelé.']);
        }
        if ($pending = DocumentTag::where('replaces_id', $old->id)->where('status', DocumentTag::STATUS_PENDING_PAYMENT)->first()) {
            return [$pending, $pending->charges()->latest('id')->first()];
        }

        return DB::transaction(function () use ($old, $owner) {
            $new = DocumentTag::create([
                'user_id'          => $owner->id,
                'document_type_id' => $old->document_type_id,
                'label'            => $old->label,
                'doc_number_last4' => $old->doc_number_last4,
                'status'           => DocumentTag::STATUS_PENDING_PAYMENT,
                'replaces_id'      => $old->id,
            ]);
            $charge = $this->billing->bill('qr_renewal', $new, null, $owner, null, $owner);
            activity()->causedBy($owner)->performedOn($new)->withProperties(['charge' => $charge->reference])->log('Renouvellement de QR demandé');

            return [$new->fresh(), $charge];
        });
    }

    public function revoke(DocumentTag $tag, User $owner): DocumentTag
    {
        $tag->update(['status' => DocumentTag::STATUS_REVOKED, 'revoked_at' => now()]);
        activity()->causedBy($owner)->performedOn($tag)->log('QR de document désactivé');

        return $tag->fresh();
    }

    /** Ce que voit le trouveur après le scan : aucune donnée personnelle. */
    public function publicView(?DocumentTag $tag): array
    {
        if (! $tag || ! $tag->isActive()) {
            return ['valid' => false, 'message' => 'Ce QR code n\'est pas (ou plus) actif. Vous pouvez déposer le document dans un commissariat ou une brigade.'];
        }

        return [
            'valid'         => true,
            'document_type' => $tag->documentType?->TypeName,
            'message'       => 'Ce document appartient à un utilisateur DocTrack. Indiquez où vous l\'avez trouvé : son propriétaire sera prévenu immédiatement.',
        ];
    }

    /**
     * Le trouveur signale le document. Retourne ce qu'on lui affiche.
     *
     * @param array{finder_phone:string, finder_name?:?string, latitude?:?float, longitude?:?float, territory_code?:?string, place?:?string, allow_owner_contact?:bool, contact_consent?:bool} $data
     */
    public function reportFound(DocumentTag $tag, array $data, ?string $ip): array
    {
        abort_unless($tag->isActive(), 410, 'Ce QR code n\'est plus actif.');
        $tag->loadMissing(['owner', 'documentType']);

        $phoneHash = SensitiveData::hashPhone($data['finder_phone']);

        // Même trouveur, même QR, dans les 24 h : pas de nouveau signalement (évite les SMS en rafale).
        $recent = TagScan::where('document_tag_id', $tag->id)->where('finder_phone_hash', $phoneHash)
            ->where('created_at', '>=', now()->subDay())->latest('id')->first();
        if ($recent) {
            return $this->finderAnswer($recent->document?->openCollection()?->organization, true);
        }

        $territoryId = ! empty($data['territory_code']) ? Territory::where('code', $data['territory_code'])->value('id') : null;
        $consent     = (bool) ($data['contact_consent'] ?? false);
        $allowOwner  = (bool) ($data['allow_owner_contact'] ?? true);

        [$scan, $document, $collection] = DB::transaction(function () use ($tag, $data, $ip, $phoneHash, $territoryId, $consent, $allowOwner) {
            // Un signalement ouvert existe déjà pour ce QR : on le réutilise.
            $document = Document::where('document_tag_id', $tag->id)
                ->whereIn('lifecycle_status', Document::MATCHABLE)->whereNull('custody_organization_id')->latest('id')->first();

            $document ??= Document::create([
                'found_channel'     => Document::CHANNEL_QR_SCAN,
                'lifecycle_status'  => Document::LIFECYCLE_PUBLISHED,
                'statut'            => 'non récupéré',
                'document_type_id'  => $tag->document_type_id,
                'document_tag_id'   => $tag->id,
                'OwnerFirstName'    => (string) $tag->owner->FirstName,
                'OwnerLastName'     => (string) $tag->owner->LastName,
                'doc_number_last4'  => $tag->doc_number_last4,
                'Location'          => $data['place'] ?? 'Signalé par QR code',
                'found_place'       => $data['place'] ?? null,
                'found_at'          => now()->toDateString(),
                'found_latitude'    => $data['latitude'] ?? null,
                'found_longitude'   => $data['longitude'] ?? null,
                'territory_id'      => $territoryId,
                'contact_consent_at'=> $consent ? now() : null,
                'user_id'           => null,
            ]);

            $scan = TagScan::create([
                'document_tag_id'        => $tag->id,
                'document_id'            => $document->id,
                'finder_name'            => $data['finder_name'] ?? null,
                'finder_phone_encrypted' => preg_replace('/[^0-9+]/', '', $data['finder_phone']),
                'finder_phone_hash'      => $phoneHash,
                'latitude'               => $data['latitude'] ?? null,
                'longitude'              => $data['longitude'] ?? null,
                'territory_id'           => $territoryId,
                'place'                  => $data['place'] ?? null,
                'allow_owner_contact'    => $allowOwner,
                'contact_consent'        => $consent,
                'ip_hash'                => $ip ? hash('sha256', $ip . config('app.key')) : null,
                'created_at'             => now(),
            ]);
            $tag->increment('scans_count');
            $tag->forceFill(['last_scanned_at' => now()])->save();

            $collection = $consent
                ? $this->collections->open($document, null, $data['finder_phone'], $data['finder_name'] ?? null)
                : null;

            return [$scan, $document, $collection];
        });

        MatchDocumentJob::dispatch($document->id);
        $this->notifyOwner($tag, $scan, $collection?->fresh('organization')?->organization, $allowOwner ? $data['finder_phone'] : null);

        activity()->performedOn($tag)->withProperties(['document' => $document->reference, 'collection' => (bool) $collection])
            ->log('QR de document scanné par un trouveur');

        return $this->finderAnswer($collection?->fresh('organization')?->organization, false);
    }

    private function notifyOwner(DocumentTag $tag, TagScan $scan, $structure, ?string $finderPhone): void
    {
        $owner  = $tag->owner;
        $params = [
            'document_type'   => $tag->documentType?->TypeName ?? 'document',
            'label'           => $tag->label ? ' (' . $tag->label . ')' : '',
            'place'           => $scan->place ?: 'un lieu non précisé',
            'structure'       => $structure?->name,
            'finder_name'     => $scan->finder_name ?: 'le trouveur',
            'finder_phone'    => $finderPhone,
        ];
        $context = ['subject' => $tag, 'recipient' => $owner, 'organization_id' => $structure?->id];

        $template = match (true) {
            $structure && $finderPhone => 'tag.found.both',
            (bool) $structure          => 'tag.found.collection',
            (bool) $finderPhone        => 'tag.found.contact',
            default                    => 'tag.found.minimal',
        };

        $this->notifier->sms($template, $owner->Phone, $params, $context + ['dedupe' => 'tag.found:' . $scan->id]);
        if ($owner->email) {
            $this->notifier->mailTo('tag.found', $owner->email, $params + [
                'next' => $this->notifier->render($this->notifier->template('sms', $template), $params),
            ], $context + ['dedupe' => 'tag.found.mail:' . $scan->id]);
        }
    }

    private function finderAnswer($structure, bool $duplicate): array
    {
        return [
            'success'   => true,
            'duplicate' => $duplicate,
            'message'   => $structure
                ? 'Merci ! Le propriétaire est prévenu. ' . $structure->name . ' va vous appeler pour récupérer le document.'
                : 'Merci ! Le propriétaire est prévenu et pourra vous contacter.',
            'structure' => $structure?->only(['name', 'address', 'phone']),
        ];
    }
}

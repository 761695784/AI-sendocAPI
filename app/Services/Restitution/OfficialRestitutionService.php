<?php

namespace App\Services\Restitution;

use App\Models\CollectionRequest;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\Organization;
use App\Models\Restitution;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Access\OrganizationAccess;
use App\Services\Collections\CollectionService;
use App\Services\Matching\MatchReviewService;
use App\Services\Matching\NameNormalizer;
use App\Services\Notifications\Notifier;
use App\Support\PrivateFiles;
use App\Support\SensitiveData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Restitution officielle au guichet.
 *
 * Règles :
 *  - le document doit être sous la garde de la structure de l'agent ;
 *  - l'agent certifie que la photo de la pièce présentée correspond à la personne ;
 *  - le nom présenté doit correspondre au nom inscrit sur le document
 *    (sinon dérogation motivée par un responsable) ;
 *  - date de naissance différente = refus, sans dérogation possible ;
 *  - procuration : pièce du mandataire + procuration vérifiée, si le type de document l'autorise.
 * On ne garde ni copie ni numéro complet des pièces : type, 4 derniers chiffres, empreinte.
 */
class OfficialRestitutionService
{
    public function __construct(
        private NameNormalizer $names,
        private OrganizationAccess $access,
        private MatchReviewService $matches,
        private DirectRestitutionService $direct,
        private CollectionService $collections,
        private Notifier $notifier,
    ) {}

    /** Ce que l'agent doit savoir avant la remise (préparation de l'écran). */
    public function prepare(Document $document): array
    {
        $document->loadMissing(['documentType', 'custodyOrganization']);
        $match = $this->confirmedMatch($document);
        $decl  = $match?->declaration;

        return [
            'document' => [
                'uuid'             => $document->uuid,
                'reference'        => $document->reference,
                'document_type'    => $document->documentType?->TypeName,
                'owner_first_name' => $document->OwnerFirstName,
                'owner_last_name'  => $document->OwnerLastName,
                'birth_date'       => $document->birth_date_in_doc?->toDateString(),
                'doc_number_last4' => $document->doc_number_last4,
                'lifecycle_status' => $document->lifecycle_status,
            ],
            'confirmed_match' => $match ? [
                'uuid'                  => $match->uuid,
                'declaration_reference' => $decl?->reference,
                'declared_by'           => $decl?->citizenProfile
                    ? trim($decl->citizenProfile->first_name . ' ' . $decl->citizenProfile->last_name)
                    : trim(($decl?->user?->FirstName ?? '') . ' ' . ($decl?->user?->LastName ?? '')),
            ] : null,
            'proxy_allowed'   => (bool) ($document->documentType?->allows_proxy ?? true),
            'requirements'    => [
                'Pièce d\'identité en cours de validité de la personne qui retire',
                'La photo de la pièce correspond à la personne présente',
                'Le nom de la pièce correspond au nom inscrit sur le document',
                'Procuration signée + pièce du mandataire en cas de retrait pour autrui',
            ],
            'can_restitute'   => $this->blockingReason($document, $document->custodyOrganization) === null,
            'blocking_reason' => $this->blockingReason($document, $document->custodyOrganization),
        ];
    }

    public function restitute(Document $document, User $agent, Organization $structure, array $data): Restitution
    {
        if ($reason = $this->blockingReason($document, $structure)) {
            throw ValidationException::withMessages(['document' => $reason]);
        }

        $isProxy  = $data['recipient_type'] === Restitution::RECIPIENT_PROXY;
        $owner    = $data['owner'];
        $checks   = $data['checks'];

        // ── Contrôles d'identité ──────────────────────────────
        if (empty($checks['identity_photo_matches'])) {
            throw ValidationException::withMessages(['checks.identity_photo_matches' => 'Vous devez certifier que la photo de la pièce présentée correspond à la personne.']);
        }

        if ($isProxy) {
            if (! ($document->documentType?->allows_proxy ?? true)) {
                throw ValidationException::withMessages(['recipient_type' => 'Ce type de document ne peut être retiré que par son titulaire.']);
            }
            if (empty($checks['power_of_attorney_verified'])) {
                throw ValidationException::withMessages(['checks.power_of_attorney_verified' => 'La procuration doit être vérifiée.']);
            }
        }

        if ($document->birth_date_in_doc && ! empty($owner['birth_date'])
            && ! $document->birth_date_in_doc->isSameDay(\Illuminate\Support\Carbon::parse($owner['birth_date']))) {
            throw ValidationException::withMessages(['owner.birth_date' => 'La date de naissance ne correspond pas à celle du document : restitution impossible.']);
        }

        $similarity = $this->nameSimilarity($owner['first_name'], $owner['last_name'], $document->OwnerFirstName, $document->OwnerLastName);
        $override   = null;
        if ($similarity < config('doctrack.restitution.name_threshold', 0.85)) {
            if (empty($data['name_override_reason'])) {
                throw ValidationException::withMessages(['owner.last_name' => sprintf(
                    'Le nom présenté ne correspond pas au document (%s %s). Un responsable peut déroger en indiquant le motif (ex. nom d\'épouse).',
                    $document->OwnerFirstName, $document->OwnerLastName)]);
            }
            if (! $this->access->can($agent, 'restitutions.override', $structure)) {
                throw ValidationException::withMessages(['name_override_reason' => 'Seul un responsable de la structure peut autoriser cette dérogation.']);
            }
            $override = $data['name_override_reason'];
        }

        // ── Remise ─────────────────────────────────────────────
        $match = $this->confirmedMatch($document);

        $restitution = DB::transaction(function () use ($document, $agent, $structure, $data, $isProxy, $owner, $checks, $similarity, $override, $match) {
            [$ownerLast4, $ownerHash] = $this->idFingerprint($owner['id_number'] ?? null);
            [$proxyLast4, $proxyHash] = $isProxy ? $this->idFingerprint($data['proxy']['id_number']) : [null, null];

            $restitution = Restitution::create([
                'document_id'              => $document->id,
                'organization_id'          => $structure->id,
                'agent_user_id'            => $agent->id,
                'document_match_id'        => $match?->id,
                'declaration_de_perte_id'  => $match?->declaration_de_perte_id,
                'declaration_item_id'      => $match?->declaration_item_id,
                'owner_citizen_profile_id' => $match?->declaration?->citizen_profile_id,
                'recipient_type'           => $data['recipient_type'],
                'owner_first_name'         => $owner['first_name'],
                'owner_last_name'          => $owner['last_name'],
                'owner_birth_date'         => $owner['birth_date'] ?? null,
                'owner_id_type'            => $owner['id_type'] ?? null,
                'owner_id_last4'           => $ownerLast4,
                'owner_id_hash'            => $ownerHash,
                'owner_phone_encrypted'    => $this->cleanPhone($owner['phone'] ?? null),
                'proxy_first_name'         => $isProxy ? $data['proxy']['first_name'] : null,
                'proxy_last_name'          => $isProxy ? $data['proxy']['last_name'] : null,
                'proxy_id_type'            => $isProxy ? $data['proxy']['id_type'] : null,
                'proxy_id_last4'           => $proxyLast4,
                'proxy_id_hash'            => $proxyHash,
                'proxy_phone_encrypted'    => $isProxy ? $this->cleanPhone($data['proxy']['phone'] ?? null) : null,
                'proxy_relationship'       => $isProxy ? ($data['proxy']['relationship'] ?? null) : null,
                'power_of_attorney_ref'    => $isProxy ? ($data['proxy']['power_of_attorney_ref'] ?? null) : null,
                'checks'                   => [
                    'identity_photo_matches'     => true,
                    'power_of_attorney_verified' => $isProxy ? true : null,
                    'without_prior_declaration'  => $match === null,
                ],
                'name_similarity'          => round($similarity, 3),
                'name_override_reason'     => $override,
                'name_override_by'         => $override ? $agent->id : null,
                'handed_over_at'           => now(),
            ]);

            StatusTransition::record($restitution, null, Restitution::STATUS_COMPLETED,
                ($isProxy ? 'Remis au mandataire ' . $data['proxy']['first_name'] . ' ' . $data['proxy']['last_name'] : 'Remis au titulaire'), $agent, $structure->id);

            // Document, correspondances, déclaration.
            $document->moveTo(Document::LIFECYCLE_RESTITUTED, 'Restitution officielle ' . $restitution->reference, $agent);
            $this->matches->applyRestitution($document, $agent, 'Restitution officielle ' . $restitution->reference);
            $this->matches->supersedeForDocument($document, $agent, 'Document restitué au guichet');
            $this->direct->cancelForDocument($document, $agent, 'Document restitué au guichet');
            $this->collections->cancelForDocument($document, $agent, 'Document restitué au guichet');

            activity()->causedBy($agent)->performedOn($restitution)
                ->withProperties([
                    'reference'  => $restitution->reference,
                    'document'   => $document->reference,
                    'proxy'      => $isProxy,
                    'similarity' => round($similarity, 3),
                    'override'   => $override !== null,
                ])
                ->log('Restitution officielle au guichet');

            return $restitution;
        });

        $this->render($restitution);
        $this->notifyAfterHandover($restitution);

        return $restitution->fresh(['document.documentType', 'organization', 'agent']);
    }

    /** Remise erronée : annulation dans le délai, le document revient sous garde. */
    public function void(Restitution $restitution, User $actor, string $reason): Restitution
    {
        if ($restitution->isVoided()) {
            throw ValidationException::withMessages(['restitution' => 'Cette restitution est déjà annulée.']);
        }
        $window = config('doctrack.restitution.void_window_hours', 72);
        if ($restitution->handed_over_at->lt(now()->subHours($window))) {
            throw ValidationException::withMessages(['restitution' => "Délai d'annulation dépassé ({$window} h)."]);
        }

        DB::transaction(function () use ($restitution, $actor, $reason) {
            $restitution->fill([
                'status'      => Restitution::STATUS_VOIDED,
                'voided_at'   => now(),
                'voided_by'   => $actor->id,
                'void_reason' => $reason,
            ])->save();
            StatusTransition::record($restitution, Restitution::STATUS_COMPLETED, Restitution::STATUS_VOIDED, $reason, $actor, $restitution->organization_id);

            $document = $restitution->document;
            $document->moveTo($restitution->document_match_id ? Document::LIFECYCLE_MATCHED : Document::LIFECYCLE_IN_CUSTODY,
                'Annulation de la restitution ' . $restitution->reference, $actor);

            if ($match = $restitution->match) {
                $match->item?->update(['status' => \App\Models\DeclarationItem::STATUS_FOUND]);
                if ($match->declaration && $match->declaration->status === \App\Models\DeclarationDePerte::STATUS_RESTITUTED) {
                    $match->declaration->moveTo(\App\Models\DeclarationDePerte::STATUS_MATCHED, 'Annulation de la restitution ' . $restitution->reference, $actor);
                }
            }

            activity()->causedBy($actor)->performedOn($restitution)
                ->withProperties(['reference' => $restitution->reference, 'reason' => $reason])
                ->log('Restitution annulée');
        });

        $this->render($restitution->fresh());

        return $restitution->fresh();
    }

    /** Scan de la décharge signée (PDF ou photo), stocké sur le disque privé. */
    public function attachSignedScan(Restitution $restitution, UploadedFile $file, User $agent): Restitution
    {
        PrivateFiles::delete($restitution->signed_scan_path);
        $path = 'restitutions/decharges-signees/' . $restitution->reference . '.' . strtolower($file->getClientOriginalExtension() ?: 'pdf');
        PrivateFiles::put($path, file_get_contents($file->getRealPath()));

        $restitution->forceFill(['signed_scan_path' => $path, 'signed_scan_uploaded_at' => now()])->save();
        activity()->causedBy($agent)->performedOn($restitution)->withProperties(['reference' => $restitution->reference])
            ->log('Décharge signée jointe');

        return $restitution->fresh();
    }

    /** Génère la décharge PDF (disque privé). */
    public function render(Restitution $restitution): void
    {
        $restitution->loadMissing(['document.documentType', 'organization.parent', 'agent.memberships', 'declaration', 'voider']);
        $structure = $restitution->organization;

        $qrSvg = (string) QrCode::format('svg')->size(130)->margin(0)->errorCorrection('M')->generate($restitution->verificationUrl());

        $pdf = Pdf::loadView('certificats.decharge_restitution', [
            'r'           => $restitution,
            'document'    => $restitution->document,
            'structure'   => $structure,
            'institution' => $structure->institution(),
            'agent'       => $restitution->agent,
            'matricule'   => $restitution->agent?->memberships->firstWhere('organization_id', $structure->id)?->matricule,
            'qrDataUri'   => 'data:image/svg+xml;base64,' . base64_encode($qrSvg),
        ])->setPaper('A4', 'portrait');

        $path = 'restitutions/decharges/' . $restitution->reference . '.pdf';
        PrivateFiles::put($path, $pdf->output());
        $restitution->forceFill(['pdf_path' => $path])->saveQuietly();
    }

    public function blockingReason(Document $document, ?Organization $structure): ?string
    {
        return match (true) {
            $document->trashed()                                           => 'Ce document a été supprimé.',
            $document->lifecycle_status === Document::LIFECYCLE_RESTITUTED => 'Ce document a déjà été restitué.',
            ! $document->isInCustody()                                     => 'Ce document n\'est pas sous la garde d\'une structure.',
            ! $structure || $document->custody_organization_id !== $structure->id => 'Ce document est conservé par une autre structure.',
            ! in_array($document->lifecycle_status, [Document::LIFECYCLE_IN_CUSTODY, Document::LIFECYCLE_MATCHED, Document::LIFECYCLE_RESTITUTION_PENDING], true)
                                                                           => 'Ce document ne peut pas être restitué dans son état actuel.',
            default                                                        => null,
        };
    }

    /** Meilleure similarité (ordre normal ou inversé), sur le moins bon des deux noms. */
    public function nameSimilarity(?string $firstA, ?string $lastA, ?string $firstB, ?string $lastB): float
    {
        $direct  = min($this->names->similarity($firstA, $firstB), $this->names->similarity($lastA, $lastB));
        $swapped = min($this->names->similarity($firstA, $lastB), $this->names->similarity($lastA, $firstB));

        return max($direct, $swapped);
    }

    private function confirmedMatch(Document $document): ?DocumentMatch
    {
        return $document->matches()->where('status', DocumentMatch::STATUS_CONFIRMED)
            ->with(['declaration.user', 'declaration.citizenProfile'])->latest('id')->first();
    }

    private function cleanPhone(?string $phone): ?string
    {
        $clean = $phone ? preg_replace('/[^0-9+]/', '', $phone) : null;

        return $clean ?: null;
    }

    /** @return array{0: ?string, 1: ?string} [4 derniers caractères, empreinte] */
    private function idFingerprint(?string $number): array
    {
        $normalized = SensitiveData::normalizeDocNumber($number);

        return $normalized ? [substr($normalized, -4), SensitiveData::hash($normalized)] : [null, null];
    }

    private function notifyAfterHandover(Restitution $r): void
    {
        $doc       = $r->document;
        $structure = $r->organization;
        $decl      = $r->declaration;

        // Propriétaire : reçu de la remise.
        $ownerPhone = $r->owner_phone_encrypted ?: ($decl?->user?->Phone ?: $decl?->citizenProfile?->phone());
        $this->notifier->sms('restitution.official.owner', $ownerPhone, [
            'owner_name' => $doc->OwnerFirstName . ' ' . $doc->OwnerLastName,
            'structure'  => $structure->name,
            'reference'  => $r->reference,
            'verify_url' => $r->verificationUrl(),
        ], ['subject' => $r, 'organization_id' => $structure->id, 'recipient' => $decl?->user ?? $decl?->citizenProfile, 'dedupe' => 'restitution.official.owner:' . $r->id]);

        // Trouveur : merci, le document est rentré chez son propriétaire.
        $collection  = CollectionRequest::where('document_id', $doc->id)->latest('id')->first();
        $finderPhone = $doc->finder?->phone() ?: ($collection?->finderPhone() ?: ($doc->found_channel === Document::CHANNEL_CITIZEN ? $doc->user?->Phone : null));
        if ($finderPhone) {
            $this->notifier->sms('restitution.official.finder', $finderPhone, [
                'structure' => $structure->name,
            ], ['subject' => $r, 'organization_id' => $structure->id, 'dedupe' => 'restitution.official.finder:' . $r->id]);
        }
    }
}

<?php

namespace App\Services\Declarations;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Models\Organization;
use App\Models\Restitution;
use App\Models\Territory;
use App\Models\User;
use App\Services\Access\OrganizationAccess;
use App\Services\Billing\BillingManager;
use App\Services\Matching\NameNormalizer;
use App\Support\SensitiveData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passerelle « déclaration en ligne → certificat de perte ».
 *
 * Le citoyen a déclaré sa perte en ligne (récépissé DocTrack, aucune structure).
 * Il se présente dans n'importe quelle structure habilitée avec sa référence ou
 * le QR code du récépissé. L'agent :
 *   1. retrouve la déclaration (résumé minimal : type, initiales) ;
 *   2. vérifie l'identité de la personne présente (pièce, photo, nom) ;
 *   3. complète éventuellement lieu, date, circonstances ;
 *   4. certifie : la déclaration est rattachée à sa structure et un certificat
 *      officiel est émis (même tarification qu'une déclaration au guichet).
 *
 * La déclaration reste la même : pas de doublon, les correspondances sont conservées.
 */
class OnlineDeclarationCertificationService
{
    public function __construct(
        private OfficialCertificateService $certificates,
        private OrganizationAccess $access,
        private NameNormalizer $names,
    ) {}

    /**
     * Retrouve une déclaration en ligne par sa référence (DT-2026-000123)
     * ou par le code de vérification de son récépissé (QR code).
     */
    public function find(string $query): ?DeclarationDePerte
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $byReference = DeclarationDePerte::query()
            ->where('channel', DeclarationDePerte::CHANNEL_ONLINE)
            ->where('reference', strtoupper($query))
            ->first();
        if ($byReference) {
            return $byReference;
        }

        // Lien complet du QR code (…/verifier/CODE) ou code seul.
        $code = CertificateVerifier::normalize(basename(parse_url($query, PHP_URL_PATH) ?: $query));
        if (strlen($code) < 8) {
            return null;
        }

        $receipt = CertificatDePerte::query()
            ->where('kind', CertificatDePerte::KIND_RECEIPT)
            ->where('verification_code', $code)
            ->first();

        $declaration = $receipt?->declarationDePerte;

        return $declaration && $declaration->channel === DeclarationDePerte::CHANNEL_ONLINE && ! $declaration->trashed()
            ? $declaration
            : null;
    }

    /**
     * Résumé affiché à l'agent AVANT la vérification d'identité : aucune donnée
     * nominative complète, aucun numéro (type de pièce, initiales, date).
     */
    public function lookupSummary(DeclarationDePerte $declaration): array
    {
        $declaration->loadMissing(['items.documentType', 'documentType', 'officialCertificate.issuingOrganization']);

        $types = $declaration->items->isNotEmpty()
            ? $declaration->items->map(fn ($i) => $i->documentType?->TypeName)->filter()->unique()->values()->all()
            : array_values(array_filter([$declaration->documentType?->TypeName]));

        $official = $declaration->officialCertificate;

        return [
            'uuid'            => $declaration->uuid,
            'reference'       => $declaration->reference,
            'declared_at'     => $declaration->created_at?->toDateString(),
            'status'          => $declaration->status,
            'document_types'  => $types,
            'holder_initials' => mb_strtoupper(mb_substr((string) $declaration->FirstNameInDoc, 0, 1)) . '. '
                . mb_strtoupper(mb_substr((string) $declaration->LastNameInDoc, 0, 1)) . '.',
            'has_doc_number'  => (bool) ($declaration->doc_number_hash || $declaration->items->first()?->doc_number_hash),
            'lost_at'         => $declaration->lost_at?->toDateString(),
            'lost_place'      => $declaration->lost_place,
            'can_certify'     => $declaration->canBeCertified(),
            'certified'       => $declaration->isCertified() ? [
                'date'      => $declaration->certified_at?->toDateString(),
                'structure' => $official?->issuingOrganization?->name ?? $declaration->organization?->name,
            ] : null,
        ];
    }

    /**
     * @param array{
     *   person: array{first_name:string, last_name:string, birth_date?:?string, id_type?:?string, id_number?:?string},
     *   checks: array{identity_photo_matches:bool},
     *   name_override_reason?: ?string,
     *   lost_at?: ?string, lost_place?: ?string, territory_code?: ?string, circumstances?: ?string,
     *   doc_number?: ?string
     * } $data
     */
    public function certify(DeclarationDePerte $declaration, User $agent, Organization $structure, array $data): DeclarationDePerte
    {
        if (! $declaration->canBeCertified()) {
            throw ValidationException::withMessages(['declaration' => $declaration->isCertified()
                ? 'Cette déclaration a déjà été certifiée.'
                : 'Cette déclaration ne peut pas être certifiée (statut : ' . $declaration->status . ').']);
        }

        $person = $data['person'];

        // ── Contrôles d'identité ──────────────────────────────
        $hasIdDocument = ! empty($person['id_number']);
        if ($hasIdDocument && empty($data['checks']['identity_photo_matches'])) {
            throw ValidationException::withMessages(['checks.identity_photo_matches' => 'Vous devez certifier que la photo de la pièce présentée correspond à la personne.']);
        }
        // Sans aucune pièce (tout a été perdu) : l'agent note comment il a vérifié l'identité.
        if (! $hasIdDocument && mb_strlen(trim((string) ($data['circumstances'] ?? ''))) < 10) {
            throw ValidationException::withMessages(['circumstances' => 'Sans pièce d\'identité, indiquez dans les circonstances comment l\'identité a été vérifiée (témoin, extrait de naissance, carte professionnelle…).']);
        }

        if ($declaration->birth_date_in_doc && ! empty($person['birth_date'])
            && ! $declaration->birth_date_in_doc->isSameDay(Carbon::parse($person['birth_date']))) {
            throw ValidationException::withMessages(['person.birth_date' => 'La date de naissance ne correspond pas à celle de la déclaration : certification impossible.']);
        }

        $similarity = $this->nameSimilarity($person['first_name'], $person['last_name'], $declaration->FirstNameInDoc, $declaration->LastNameInDoc);
        $override   = null;
        if ($similarity < config('doctrack.restitution.name_threshold', 0.85)) {
            if (empty($data['name_override_reason'])) {
                throw ValidationException::withMessages(['person.last_name' => sprintf(
                    'Le nom de la personne présente ne correspond pas à la déclaration (%s %s). Un responsable peut déroger en indiquant le motif (ex. nom d\'épouse).',
                    $declaration->FirstNameInDoc, $declaration->LastNameInDoc)]);
            }
            if (! $this->access->can($agent, 'declarations.override', $structure)) {
                throw ValidationException::withMessages(['name_override_reason' => 'Seul un responsable de la structure peut autoriser cette dérogation.']);
            }
            $override = $data['name_override_reason'];
        }

        // Pièce présentée facultative : une victime peut avoir perdu toutes ses pièces d'un coup.
        $normalizedId = ! empty($person['id_number']) ? SensitiveData::normalizeDocNumber($person['id_number']) : null;

        DB::transaction(function () use ($declaration, $agent, $structure, $data, $person, $normalizedId, $similarity, $override) {
            $fill = [
                'organization_id'          => $structure->id,
                'certified_at'             => now(),
                'certified_by_user_id'     => $agent->id,
                'certification_first_name' => $person['first_name'],
                'certification_last_name'  => $person['last_name'],
                'certification_birth_date' => $person['birth_date'] ?? null,
                'certification_id_type'    => $person['id_type'] ?? null,
                'certification_id_last4'   => $normalizedId ? substr($normalizedId, -4) : null,
                'certification_id_hash'    => $normalizedId ? SensitiveData::hash($normalizedId) : null,
            ];

            // Compléments saisis au guichet : ne remplacent que ce qui est fourni.
            foreach (['lost_at', 'lost_place', 'circumstances'] as $field) {
                if (! empty($data[$field])) {
                    $fill[$field] = $data[$field];
                }
            }
            if (! empty($data['territory_code'])) {
                $fill['territory_id'] = Territory::where('code', $data['territory_code'])->value('id');
            } elseif (! $declaration->territory_id) {
                $fill['territory_id'] = $structure->territory_id;
            }
            if (! $declaration->birth_date_in_doc && ! empty($person['birth_date'])) {
                $fill['birth_date_in_doc'] = $person['birth_date'];
            }

            $declaration->forceFill($fill)->save();

            // Numéro du document perdu, s'il n'avait pas été donné en ligne.
            $item = $declaration->ensureLegacyItem();
            if (! empty($data['doc_number']) && $item && ! $item->doc_number_hash) {
                $item->setDocNumber($data['doc_number'])->save();
                $declaration->forceFill([
                    'doc_number_hash'      => $item->doc_number_hash,
                    'doc_number_encrypted' => $item->docNumber(),
                ])->saveQuietly();
            }

            activity()
                ->causedBy($agent)
                ->performedOn($declaration)
                ->withProperties([
                    'reference'       => $declaration->reference,
                    'organization'    => $structure->uuid,
                    'id_type'         => $person['id_type'] ?? null,
                    'name_similarity' => round($similarity, 3),
                    'name_override'   => $override,
                ])
                ->log('Déclaration en ligne certifiée au guichet');
        });

        // Même service que la déclaration au guichet : en pilote gratuit (tracé) → certificat immédiat ;
        // en mode live, le certificat est délivré une fois le frais payé ou exonéré.
        $citizen = $declaration->citizenProfile;
        app(BillingManager::class)->bill('counter_declaration', $declaration, $structure,
            $citizen?->user ?? $declaration->user, $citizen, $agent);

        return $declaration->fresh([
            'items.documentType', 'citizenProfile', 'organization.parent', 'recordedBy', 'certifiedBy',
            'certificat', 'receipt', 'officialCertificate', 'charges.service', 'territory',
        ]);
    }

    private function nameSimilarity(?string $firstA, ?string $lastA, ?string $firstB, ?string $lastB): float
    {
        $direct  = min($this->names->similarity($firstA, $firstB), $this->names->similarity($lastA, $lastB));
        $swapped = min($this->names->similarity($firstA, $lastB), $this->names->similarity($lastA, $firstB));

        return max($direct, $swapped);
    }

    /** Types de pièces acceptées pour vérifier l'identité (mêmes que la restitution). */
    public static function idTypes(): array
    {
        return Restitution::ID_TYPES;
    }
}

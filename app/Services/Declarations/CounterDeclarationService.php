<?php

namespace App\Services\Declarations;

use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\Territory;
use App\Models\User;
use App\Jobs\MatchDeclarationJob;
use App\Services\Matching\MatchReviewService;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement d'une déclaration de perte au guichet d'une structure.
 *
 * Victime → agent → déclaration (rattachée à l'agent et à la structure)
 *         → référence unique → certificat officiel vérifiable.
 */
class CounterDeclarationService
{
    public function __construct(
        private OfficialCertificateService $certificates,
        private MatchReviewService $matches,
    ) {}

    /**
     * @param array{
     *   lost_at?: string|null, lost_place?: string|null, territory_code?: string|null,
     *   circumstances?: string|null,
     *   items: array<int, array{document_type_id:int, first_name:string, last_name:string, birth_date?:string|null, doc_number?:string|null}>
     * } $data
     */
    public function register(User $agent, Organization $structure, CitizenProfile $citizen, array $data): DeclarationDePerte
    {
        $declaration = DB::transaction(function () use ($agent, $structure, $citizen, $data) {
            $items = $data['items'];
            $first = $items[0];
            $types = DocumentType::whereIn('id', collect($items)->pluck('document_type_id'))->pluck('TypeName', 'id');

            $declaration = DeclarationDePerte::create([
                'channel'             => DeclarationDePerte::CHANNEL_COUNTER,
                'status'              => DeclarationDePerte::STATUS_REGISTERED,
                'citizen_profile_id'  => $citizen->id,
                'user_id'             => $citizen->user_id, // visible dans l'espace du citoyen s'il a un compte
                'organization_id'     => $structure->id,
                'recorded_by_user_id' => $agent->id,
                'lost_at'             => $data['lost_at'] ?? null,
                'lost_place'          => $data['lost_place'] ?? null,
                'territory_id'        => ! empty($data['territory_code'])
                    ? Territory::where('code', $data['territory_code'])->value('id')
                    : $structure->territory_id,
                'circumstances'       => $data['circumstances'] ?? null,

                // Colonnes V1 = première pièce (compatibilité des écrans et stats existants).
                'Title'             => 'Déclaration de perte — ' . collect($items)->map(fn ($i) => $types[$i['document_type_id']] ?? '')->filter()->unique()->implode(', '),
                'FirstNameInDoc'    => $first['first_name'],
                'LastNameInDoc'     => $first['last_name'],
                'document_type_id'  => $first['document_type_id'],
                'birth_date_in_doc' => $first['birth_date'] ?? null,
                // Le numéro n'est PAS écrit en clair (DocIdentification) : chiffré dans les pièces.
            ]);

            foreach ($items as $item) {
                (new DeclarationItem([
                    'declaration_de_perte_id' => $declaration->id,
                    'document_type_id'        => $item['document_type_id'],
                    'first_name'              => $item['first_name'],
                    'last_name'               => $item['last_name'],
                    'birth_date_in_doc'       => $item['birth_date'] ?? null,
                ]))->setDocNumber($item['doc_number'] ?? null)->save();
            }

            // Hachage de la première pièce aussi sur la déclaration (matching V1/V2).
            $firstItem = $declaration->items()->orderBy('id')->first();
            $declaration->forceFill([
                'doc_number_hash'      => $firstItem->doc_number_hash,
                'doc_number_encrypted' => $firstItem->docNumber(),
            ])->saveQuietly();

            activity()
                ->causedBy($agent)
                ->performedOn($declaration)
                ->withProperties([
                    'reference'    => $declaration->reference,
                    'channel'      => 'counter',
                    'items'        => count($items),
                    'organization' => $structure->uuid,
                ])
                ->log('Déclaration de perte enregistrée au guichet');

            return $declaration;
        });

        // Frais de déclaration : en pilote gratuit (tracé) → certificat immédiat ;
        // en mode live, le certificat est délivré une fois le frais payé ou exonéré.
        app(\App\Services\Billing\BillingManager::class)->bill('counter_declaration', $declaration, $structure,
            $citizen->user, $citizen, $agent);

        MatchDeclarationJob::dispatch($declaration->id);

        return $declaration->fresh(['items.documentType', 'citizenProfile', 'organization.parent', 'recordedBy', 'certificat', 'charges.service']);
    }

    /**
     * Annulation (erreur de saisie, doublon…) : la déclaration reste tracée,
     * le certificat est révoqué et la vérification publique l'indique.
     */
    public function cancel(DeclarationDePerte $declaration, User $actor, string $reason): DeclarationDePerte
    {
        DB::transaction(function () use ($declaration, $actor, $reason) {
            $declaration->update([
                'status'         => DeclarationDePerte::STATUS_CANCELLED,
                'closed_at'      => now(),
                'closure_reason' => $reason,
            ]);

            $declaration->certificats()->whereNull('revoked_at')->update([
                'revoked_at'        => now(),
                'revocation_reason' => 'Déclaration annulée : ' . $reason,
            ]);

            activity()
                ->causedBy($actor)
                ->performedOn($declaration)
                ->withProperties(['reference' => $declaration->reference, 'reason' => $reason])
                ->log('Déclaration annulée');

            $this->matches->supersedeForDeclaration($declaration, $actor);
        });

        if ($declaration->citizenProfile) {
            app(Notifier::class)->sms('counter_declaration.cancelled', $declaration->citizenProfile->phone(), [
                'reference' => $declaration->reference,
                'structure' => $declaration->organization?->name ?? 'la structure',
                'reason'    => $reason,
            ], ['recipient' => $declaration->citizenProfile, 'subject' => $declaration, 'organization_id' => $declaration->organization_id]);
        }

        return $declaration->fresh(['items.documentType', 'citizenProfile', 'organization', 'recordedBy', 'certificat']);
    }
}

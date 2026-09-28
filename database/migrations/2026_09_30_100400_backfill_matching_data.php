<?php

use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Services\Matching\NameNormalizer;
use App\Support\SensitiveData;
use Illuminate\Database\Migrations\Migration;

/**
 * Données V1 existantes → prêtes pour le moteur de correspondance :
 *  - documents : clés phonétiques + numéro haché/chiffré ;
 *  - déclarations sans pièce : création de la pièce à partir des colonnes V1.
 * Aucun matching n'est lancé ici (commande dédiée plus tard si besoin).
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = app(NameNormalizer::class);

        Document::withTrashed()
            ->where(fn ($q) => $q->whereNull('last_name_key')
                ->orWhere(fn ($q) => $q->whereNotNull('DocIdentification')->whereNull('doc_number_hash')))
            ->chunkById(200, function ($documents) use ($names) {
                foreach ($documents as $document) {
                    $normalized = SensitiveData::normalizeDocNumber($document->DocIdentification);
                    $document->forceFill([
                        'first_name_key'       => $names->key($document->OwnerFirstName),
                        'last_name_key'        => $names->key($document->OwnerLastName),
                        'doc_number_hash'      => SensitiveData::hash($normalized),
                        'doc_number_encrypted' => $normalized,
                        'doc_number_last4'     => $normalized ? substr($normalized, -4) : null,
                    ])->saveQuietly();
                }
            });

        DeclarationDePerte::withTrashed()
            ->whereDoesntHave('items')
            ->chunkById(200, fn ($declarations) => $declarations->each->ensureLegacyItem());
    }

    public function down(): void
    {
        // Données dérivées : supprimées avec les colonnes/tables des migrations précédentes.
    }
};

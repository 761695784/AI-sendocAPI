<?php

namespace App\Services\Declarations;

use App\Models\CertificatDePerte;

/**
 * Vérification publique d'un certificat : on confirme l'authenticité
 * sans exposer de données personnelles (initiales seulement, aucun numéro).
 */
class CertificateVerifier
{
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function find(string $code): ?CertificatDePerte
    {
        $code = self::normalize($code);
        if (strlen($code) < 8) {
            return null;
        }

        return CertificatDePerte::withTrashed()
            ->with(['declarationDePerte.items.documentType', 'declarationDePerte.documentType', 'issuingOrganization.parent'])
            ->where('verification_code', $code)
            ->first();
    }

    public function summary(?CertificatDePerte $certificat): array
    {
        if (! $certificat) {
            return ['found' => false, 'status' => 'unknown'];
        }

        $declaration = $certificat->declarationDePerte;
        $revoked     = $certificat->isRevoked() || $certificat->trashed();
        $structure   = $certificat->issuingOrganization;

        $types = $declaration?->items->isNotEmpty()
            ? $declaration->items->map(fn ($i) => $i->documentType?->TypeName)->filter()->unique()->values()->all()
            : array_values(array_filter([$declaration?->documentType?->TypeName]));

        $initials = $declaration
            ? mb_strtoupper(mb_substr((string) $declaration->FirstNameInDoc, 0, 1)) . '. ' . mb_strtoupper(mb_substr((string) $declaration->LastNameInDoc, 0, 1)) . '.'
            : null;

        return [
            'found'             => true,
            'status'            => $revoked ? 'revoked' : 'valid',
            'kind'              => $certificat->kind,
            'kind_label'        => $certificat->isOfficial()
                ? 'Certificat de déclaration de perte délivré par une structure habilitée'
                : 'Récépissé DocTrack — déclaration faite en ligne, non vérifiée par une autorité',
            'certificate_number'=> $certificat->certificat_number,
            'issued_at'         => $certificat->created_at?->toDateString(),
            'issued_by'         => $structure ? [
                'structure'   => $structure->name,
                'institution' => $structure->institution()->name,
            ] : null,
            'document_types'    => $types,
            'holder_initials'   => $initials,
            'revoked_at'        => $revoked ? ($certificat->revoked_at ?? $certificat->deleted_at)?->toDateString() : null,
            'revocation_reason' => $revoked ? ($certificat->revocation_reason ?? 'Déclaration supprimée') : null,
        ];
    }
}

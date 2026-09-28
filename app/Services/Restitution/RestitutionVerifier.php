<?php

namespace App\Services\Restitution;

use App\Models\Restitution;
use App\Services\Declarations\CertificateVerifier;

/**
 * Vérification publique d'une décharge de restitution (même lien /verifier/{code}
 * que les certificats) : authenticité sans donnée personnelle (initiales seulement).
 */
class RestitutionVerifier
{
    public function find(string $code): ?Restitution
    {
        $code = CertificateVerifier::normalize($code);

        return strlen($code) < 8 ? null
            : Restitution::with(['document.documentType', 'organization.parent'])->where('verification_code', $code)->first();
    }

    public function summary(Restitution $r): array
    {
        $structure = $r->organization;

        return [
            'found'           => true,
            'type'            => 'restitution',
            'status'          => $r->isVoided() ? 'revoked' : 'valid',
            'kind'            => 'restitution',
            'kind_label'      => 'Décharge de restitution officielle' . ($r->isProxy() ? ' (retrait par procuration)' : ''),
            'certificate_number' => $r->reference,
            'issued_at'       => $r->handed_over_at->toDateString(),
            'issued_by'       => $structure ? ['structure' => $structure->name, 'institution' => $structure->institution()->name] : null,
            'document_types'  => array_values(array_filter([$r->document?->documentType?->TypeName])),
            'holder_initials' => mb_strtoupper(mb_substr($r->owner_first_name, 0, 1)) . '. ' . mb_strtoupper(mb_substr($r->owner_last_name, 0, 1)) . '.',
            'revoked_at'      => $r->voided_at?->toDateString(),
            'revocation_reason' => $r->isVoided() ? 'Restitution annulée : ' . $r->void_reason : null,
        ];
    }
}

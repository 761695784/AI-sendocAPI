<?php

namespace App\Services\Declarations;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Models\Organization;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\ReferenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Certificat de déclaration de perte émis par une structure habilitée,
 * signé par l'agent, vérifiable publiquement par QR code.
 */
class OfficialCertificateService
{
    public function issue(DeclarationDePerte $declaration, User $agent, Organization $structure): CertificatDePerte
    {
        $certificat = CertificatDePerte::create([
            'declaration_de_perte_id'   => $declaration->id,
            'certificat_number'         => ReferenceGenerator::officialCertificate(),
            'kind'                      => CertificatDePerte::KIND_OFFICIAL,
            'document_type_id'          => $declaration->document_type_id,
            'description'               => 'Certificat émis au guichet — ' . $structure->name,
            'issued_by_organization_id' => $structure->id,
            'issued_by_user_id'         => $agent->id,
        ]);

        $this->render($certificat);

        return $certificat;
    }

    /** (Re)génère le PDF sur le disque privé. */
    public function render(CertificatDePerte $certificat): void
    {
        $certificat->load([
            'declarationDePerte.items.documentType',
            'declarationDePerte.citizenProfile',
            'declarationDePerte.territory',
            'issuingOrganization.parent',
            'issuingOrganization.territory',
            'issuer.memberships',
        ]);

        $structure   = $certificat->issuingOrganization;
        $institution = $structure->institution();
        $matricule   = $certificat->issuer?->memberships->firstWhere('organization_id', $structure->id)?->matricule;

        $qrSvg = (string) QrCode::format('svg')->size(140)->margin(0)->errorCorrection('M')
            ->generate($certificat->verificationUrl());

        $pdf = Pdf::loadView('certificats.certificat_officiel', [
            'certificat'  => $certificat,
            'declaration' => $certificat->declarationDePerte,
            'structure'   => $structure,
            'institution' => $institution,
            'agent'       => $certificat->issuer,
            'matricule'   => $matricule,
            'qrDataUri'   => 'data:image/svg+xml;base64,' . base64_encode($qrSvg),
            'lh'          => \App\Support\Letterhead::for($structure),
        ])->setPaper('A4', 'portrait');

        $path = 'certificats/officiels/' . $certificat->certificat_number . '.pdf';
        PrivateFiles::put($path, $pdf->output());

        $certificat->forceFill(['pdf_path' => $path])->saveQuietly();
    }
}

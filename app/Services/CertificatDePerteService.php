<?php

namespace App\Services;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Support\PrivateFiles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Récépissé DocTrack d'une déclaration faite en ligne.
 * Ce n'est pas un certificat de perte : celui-ci est délivré au guichet d'une
 * structure habilitée (OfficialCertificateService), y compris pour une
 * déclaration en ligne certifiée ensuite (OnlineDeclarationCertificationService).
 */
class CertificatDePerteService
{
    public function genererCertificat(DeclarationDePerte $declaration): CertificatDePerte
    {

         // Vérifier si certificat existe déjà
        $existing = CertificatDePerte::where('declaration_de_perte_id', $declaration->id)
            ->where('kind', CertificatDePerte::KIND_RECEIPT)
            ->first();

        if ($existing) {
            return $existing;
        }
        // Numéro du récépissé (REC-…) : à ne pas confondre avec un certificat de perte (CP-…).
        // Les anciens récépissés gardent leur numéro CERT-….
        $certificatNumber = 'REC-' . now()->format('Ymd') . '-' . str_pad($declaration->id, 5, '0', STR_PAD_LEFT);

        // Créer le certificat
        $certificat = CertificatDePerte::create([
            'declaration_de_perte_id' => $declaration->id,
            'certificat_number' => $certificatNumber,
            'document_type_id' => $declaration->document_type_id,
            'kind' => CertificatDePerte::KIND_RECEIPT,
            'description' => 'Récépissé DocTrack généré automatiquement pour la déclaration de perte N°' . $declaration->id
        ]);
        

        // ✅ CHARGER les relations AVANT de rendre la vue
        $certificat->load(['documentType', 'declarationDePerte.user']);

        // Générer le PDF
        // QR du récépissé : l'agent le scanne au guichet pour retrouver la déclaration.
        $qrSvg = (string) QrCode::format('svg')->size(140)->margin(0)->errorCorrection('M')
            ->generate($certificat->verificationUrl());

        $pdf = Pdf::loadView('certificats.certificat_perte', [
            'certificat' => $certificat,
            'qrDataUri'  => 'data:image/svg+xml;base64,' . base64_encode($qrSvg),
        ]);

        
        $filename = 'certificats/' . $certificatNumber . '.pdf';
        // Disque privé : le PDF n'est accessible que via l'API authentifiée.
        PrivateFiles::put($filename, $pdf->output());

        // Mise à jour du chemin du PDF
        $certificat->update([
            'pdf_path' => $filename
        ]);

        return $certificat;
    }
}

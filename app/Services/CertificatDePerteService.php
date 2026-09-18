<?php

namespace App\Services;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class CertificatDePerteService
{
    public function genererCertificat(DeclarationDePerte $declaration): CertificatDePerte
    {

         // Vérifier si certificat existe déjà
        $existing = CertificatDePerte::where('declaration_de_perte_id', $declaration->id)->first();

        if ($existing) {
            return $existing;
        }
        // Générer un numéro de certificat unique
        $certificatNumber = 'CERT-' . now()->format('Ymd') . '-' . str_pad($declaration->id, 5, '0', STR_PAD_LEFT);

        // Créer le certificat
        $certificat = CertificatDePerte::create([
            'declaration_de_perte_id' => $declaration->id,
            'certificat_number' => $certificatNumber,
            'document_type_id' => $declaration->document_type_id,
            'description' => 'Certificat généré automatiquement pour la déclaration de perte N°' . $declaration->id
        ]);
        

        // ✅ CHARGER les relations AVANT de rendre la vue
        $certificat->load(['documentType', 'declarationDePerte']);

        // Générer le PDF
        $pdf = Pdf::loadView('certificats.certificat_perte', ['certificat' => $certificat]);

        
        $filename = 'certificats/' . $certificatNumber . '.pdf';
        Storage::disk('public')->put($filename, $pdf->output());

        // Mise à jour du chemin du PDF
        $certificat->update([
            'pdf_path' => $filename
        ]);

        return $certificat;
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Declarations\CertificateVerifier;

/**
 * Vérification publique d'un certificat (lien du QR code imprimé).
 * Aucune authentification ; réponse limitée à ce qui prouve l'authenticité.
 */
class CertificateVerificationController extends Controller
{
    public function __construct(
        private CertificateVerifier $verifier,
        private \App\Services\Restitution\RestitutionVerifier $restitutions,
    ) {}

    /** Certificat de perte, sinon décharge de restitution (même code, même lien). */
    private function lookup(string $code): array
    {
        if ($certificate = $this->verifier->find($code)) {
            return $this->verifier->summary($certificate) + ['type' => 'certificate'];
        }
        if ($restitution = $this->restitutions->find($code)) {
            return $this->restitutions->summary($restitution);
        }

        return $this->verifier->summary(null);
    }

    /** GET /api/v2/verify/{code} */
    public function api(string $code)
    {
        $summary = $this->lookup($code);

        return response()->json($summary, $summary['found'] ? 200 : 404);
    }

    /** GET /verifier/{code} — page HTML lisible sur téléphone */
    public function page(string $code)
    {
        $summary = $this->lookup($code);

        return response()
            ->view('verification.certificat', ['s' => $summary, 'code' => CertificateVerifier::normalize($code)], $summary['found'] ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex');
    }
}

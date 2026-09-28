<?php

return [
    // URL du front (liens dans les emails, SMS et QR codes).
    'frontend_url' => env('FRONTEND_URL', 'https://sendoctrack.netlify.app'),

    // Clé dédiée au hachage des numéros de pièce et de téléphone (recherche/matching).
    // À définir une fois pour toutes : la changer rend les anciens hachages inutilisables.
    'hash_key' => env('DOCTRACK_HASH_KEY'),

    // Page publique de vérification d'un certificat (lien du QR code imprimé).
    'verification_url' => env('CERTIFICATE_VERIFICATION_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/verifier'),

    // Délai pendant lequel l'agent auteur peut annuler sa propre saisie (heures).
    'agent_cancel_window_hours' => (int) env('AGENT_CANCEL_WINDOW_HOURS', 24),

    // Durée de validité d'un QR code (en mois).
    'qr_code_validity_months' => (int) env('QR_CODE_VALIDITY_MONTHS', 12),
];

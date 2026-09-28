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

    // Collecte des documents publiés par les citoyens.
    'collection' => [
        // Au-delà de cette distance, on préfère une structure du même département.
        'gps_max_km' => (float) env('COLLECTION_GPS_MAX_KM', 25),
    ],

    // Demande de restitution directe au trouveur : délai de réponse avant bascule vers la structure.
    'restitution' => [
        'response_hours' => (int) env('RESTITUTION_RESPONSE_HOURS', 48),

        // Restitution officielle au guichet
        'name_threshold'   => (float) env('RESTITUTION_NAME_THRESHOLD', 0.85), // similarité minimale nom présenté ↔ document
        'void_window_hours'=> (int) env('RESTITUTION_VOID_WINDOW_HOURS', 72),  // délai pour annuler une remise erronée
    ],
];

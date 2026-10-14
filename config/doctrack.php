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

    // Documentation de l'API V2 (/api/documentation/v2). Désactivable en production.
    'api_docs_enabled' => (bool) env('API_DOCS_ENABLED', true),

    // Délais de réaction attendus des structures (statistiques + relances automatiques).
    'sla' => [
        'first_contact_hours' => (int) env('SLA_FIRST_CONTACT_HOURS', 24), // collecte : 1er appel au trouveur
        'escalate_hours'      => (int) env('SLA_ESCALATE_HOURS', 48),      // sans appel → alerte à la structure supérieure
        'collection_hours'    => (int) env('SLA_COLLECTION_HOURS', 72),    // affectation → document reçu
        'review_hours'        => (int) env('SLA_REVIEW_HOURS', 48),        // correspondance à vérifier
    ],

    // Demande de restitution directe au trouveur : délai de réponse avant bascule vers la structure.
    'restitution' => [
        'response_hours' => (int) env('RESTITUTION_RESPONSE_HOURS', 48),

        // Restitution officielle au guichet
        'name_threshold'   => (float) env('RESTITUTION_NAME_THRESHOLD', 0.85), // similarité minimale nom présenté ↔ document
        'void_window_hours'=> (int) env('RESTITUTION_VOID_WINDOW_HOURS', 72),  // délai pour annuler une remise erronée
    ],

    // En-tête des documents officiels (certificats, décharges, rapports), selon l'institution de rattachement.
    // Format administratif sénégalais : « République du Sénégal », devise, puis la hiérarchie jusqu'à la structure.
    // À faire valider par chaque institution avant usage réel (intitulés exacts, sigles, signataires).
    'letterhead' => [
        'institutions' => [
            'POLICE' => [
                'lines'     => ["Ministère de l'Intérieur et de la Sécurité publique", 'Direction générale de la Police nationale'],
                'signatory' => ['commissariat' => 'Le Commissaire de police', 'poste' => 'Le Chef de poste', 'default' => 'Le Chef de service'],
            ],
            'GENDARMERIE' => [
                'lines'     => ['Ministère des Forces armées', 'Haut-Commandement de la Gendarmerie nationale'],
                'signatory' => ['brigade' => 'Le Commandant de brigade', 'poste_gendarmerie' => 'Le Chef de poste', 'compagnie' => 'Le Commandant de compagnie', 'default' => 'Le Commandant d\'unité'],
            ],
        ],
        // Mention de la plateforme (documents émis par DocTrack, sans autorité).
        'platform' => 'Plateforme nationale DocTrack',
        // Case réservée au timbre fiscal sur le certificat de perte (à confirmer auprès des structures).
        'fiscal_stamp' => (bool) env('CERTIFICATE_FISCAL_STAMP', true),
        'default_city' => env('DOCTRACK_DEFAULT_CITY', 'Dakar'),
    ],
];

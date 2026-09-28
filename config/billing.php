<?php

/*
|--------------------------------------------------------------------------
| Facturation DocTrack
|--------------------------------------------------------------------------
| mode = "pilot" : tout est gratuit, mais chaque démarche est enregistrée
|        avec sa valeur théorique (ce qui aurait été perçu) → rapport aux autorités.
| mode = "live"  : les tarifs s'appliquent ; le certificat au guichet n'est
|        délivré qu'une fois le paiement enregistré ou l'exonération accordée.
|
| Le catalogue ci-dessous sert à créer les tarifs de départ ; ensuite, le
| super admin les modifie par l'API (historique conservé).
*/

return [
    'mode' => env('BILLING_MODE', 'pilot'),

    'currency' => 'XOF',

    'catalog' => [
        'counter_declaration' => [
            'name'                => 'Déclaration de perte au guichet',
            'description'         => 'Enregistrement, certificat officiel vérifiable, correspondances et alertes.',
            'amount'              => 3000,
            'authority_share_pct' => 50,
        ],
        'qr_renewal' => [
            'name'                => 'Renouvellement de QR code',
            'description'         => 'Nouveau QR (document ou personnel), l\'ancien est désactivé.',
            'amount'              => 1000,
            'authority_share_pct' => 50,
        ],
        'restitution' => [
            'name'                => 'Restitution d\'un document',
            'description'         => 'Gratuite : ne jamais décourager la récupération.',
            'amount'              => 0,
            'authority_share_pct' => 50,
        ],
    ],

    // Paiement en ligne
    'online_provider' => env('BILLING_ONLINE_PROVIDER', 'wave'),
    'return_urls' => [
        'success' => env('PAYMENT_SUCCESS_URL', rtrim(env('FRONTEND_URL', 'https://sendoctrack.netlify.app'), '/') . '/paiement/succes'),
        'error'   => env('PAYMENT_ERROR_URL', rtrim(env('FRONTEND_URL', 'https://sendoctrack.netlify.app'), '/') . '/paiement/echec'),
    ],

    // Qui traite quoi après paiement (code du service → classe).
    'fulfillers' => [
        'counter_declaration' => \App\Services\Billing\Fulfillers\CounterDeclarationFulfiller::class,
        'qr_renewal'          => \App\Services\Billing\Fulfillers\QrRenewalFulfiller::class,
    ],
];

<?php

return [
    // URL du front (liens dans les emails, SMS et QR codes).
    'frontend_url' => env('FRONTEND_URL', 'https://sendoctrack.netlify.app'),

    // Durée de validité d'un QR code (en mois).
    'qr_code_validity_months' => (int) env('QR_CODE_VALIDITY_MONTHS', 12),
];

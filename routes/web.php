<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Vérification publique d'un certificat (lien imprimé dans le QR code).
Route::get('/verifier/{code}', [\App\Http\Controllers\CertificateVerificationController::class, 'page'])
    ->middleware('throttle:30,1')
    ->where('code', '[A-Za-z0-9\-]{4,40}')
    ->name('certificates.verify.page');

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

// Documentation de l'API V2 (Swagger UI + OpenAPI YAML importable dans Postman)
Route::get('/api/documentation/v2', [\App\Http\Controllers\ApiDocumentationController::class, 'ui'])->name('api-docs.v2');
Route::get('/api/documentation/v2/openapi.yaml', [\App\Http\Controllers\ApiDocumentationController::class, 'spec'])->name('api-docs.v2.spec');

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CertificatDePerteController;
use App\Http\Controllers\CommentaireController;
use App\Http\Controllers\DeclarationDePerteController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RapportController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES SENSIBLES — throttle strict (anti brute-force)
// ══════════════════════════════════════════════════════════════════
Route::middleware('throttle:10,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    // @deprecated — l'email passe dans l'URL. Conservé le temps d'adapter le front.
    Route::get('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/verify-email', [EmailVerificationController::class, 'verify']);
    Route::post('/found-qr', [QrCodeController::class, 'handleFoundQr']);
});

// ══════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES LECTURE — throttle souple
// ══════════════════════════════════════════════════════════════════
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    Route::post('/auth/google/finalize-account-creation', [AuthController::class, 'finalizeAccountCreation']);

    // Documents publics (vue floutée, données personnelles masquées)
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/{slug}', [DocumentController::class, 'show']);
    Route::get('documents/{document_slug}/comments', [CommentaireController::class, 'getCommentairesByDocument']);
    Route::get('lieu', [DocumentController::class, 'getPublicationsByLocation']);
});

// ══════════════════════════════════════════════════════════════════
// FICHIERS PRIVÉS — URLs signées temporaires (générées par l'API)
// ══════════════════════════════════════════════════════════════════
Route::middleware(['signed', 'throttle:120,1'])->prefix('files')->group(function () {
    Route::get('documents/{uuid}/image', [PrivateFileController::class, 'documentImage'])->name('documents.image');
    Route::get('qr-codes/{uuid}', [PrivateFileController::class, 'qrCode'])->name('files.qr');
    Route::get('rapports/{uuid}', [PrivateFileController::class, 'rapport'])->name('files.rapport');
});

// ══════════════════════════════════════════════════════════════════
// ROUTES PROTÉGÉES — utilisateur connecté
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth:api', 'active', 'throttle:60,1'])->group(function () {

    // ── Utilisateur ──
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::put('change-password', [AuthController::class, 'changePassword']);
    Route::put('profil', [AuthController::class, 'updateProfile']);
    Route::post('/renew-qr-code', [AuthController::class, 'renewQrCode']);

    // ── Certificats utilisateur ──
    Route::get('/my-certificats', [CertificatDePerteController::class, 'mesCertificats']);
    Route::get('/certificats/{slug}/voir', [CertificatDePerteController::class, 'voir']);
    Route::get('/certificats/{slug}/telecharger', [CertificatDePerteController::class, 'telecharger']);

    // ── Documents (actions authentifiées) ──
    Route::post('documents', [DocumentController::class, 'store']);
    Route::put('documents/{slug}', [DocumentController::class, 'update']);
    Route::delete('documents/{slug}', [DocumentController::class, 'destroy']);
    Route::post('documents/restore/{slug}', [DocumentController::class, 'restoreTrashedDocument']);
    Route::get('trashed', [DocumentController::class, 'trashedDocuments']);
    Route::post('documents/{slug}/restitution', [DocumentController::class, 'requestRestitution']);
    Route::get('my-publications', [DocumentController::class, 'OwnPub']);

    // ── Déclarations ──
    Route::post('declarations', [DeclarationDePerteController::class, 'store']);
    Route::get('declarations', [DeclarationDePerteController::class, 'index']);
    Route::get('declarations/{slug}', [DeclarationDePerteController::class, 'show']);
    Route::delete('declarations/{slug}', [DeclarationDePerteController::class, 'destroy']);
    Route::get('trashed-declarations', [DeclarationDePerteController::class, 'trashedDeclarations']);
    Route::post('declarations/restore/{slug}', [DeclarationDePerteController::class, 'restoreTrashedDeclaration']);
    Route::get('my-declarations', [DeclarationDePerteController::class, 'getUserDeclarations']);

    // ── Commentaires (pas de modification : la méthode n'existe pas) ──
    Route::apiResource('comments', CommentaireController::class)->except(['update']);

    // ── Notifications : l'Admin voit tout, le citoyen seulement les siennes ──
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{slug}/mark-as-read', [NotificationController::class, 'markAsRead']);
    Route::get('correspondence-emails', [NotificationController::class, 'showAllCorrespondenceEmails']);
    Route::get('restitution-emails', [NotificationController::class, 'showAllRestitutionEmails']);
    Route::get('all-emails', [NotificationController::class, 'showAllEmails']);
    Route::get('all-notifications', [NotificationController::class, 'getAllData']);
    Route::get('new-notifications', [NotificationController::class, 'getNewNotifications']);
    Route::get('restitution-count', [NotificationController::class, 'getRestitutionRequestCount']);

    // ── Types de documents : lecture pour tous les connectés ──
    Route::get('document-types', [DocumentTypeController::class, 'index']);
    Route::get('document-types/{document_type}', [DocumentTypeController::class, 'show']);
});

// ══════════════════════════════════════════════════════════════════
// ROUTES ADMIN — rôle Admin obligatoire
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth:api', 'active', 'role:super_admin,api', 'throttle:200,1'])->group(function () {

    // ── Utilisateurs ──
    Route::get('/users', [AuthController::class, 'getAllUsersWithRoles']);
    Route::delete('users/{slug}', [AuthController::class, 'deleteUser']);
    Route::post('create-admin', [AuthController::class, 'createAdmin']);

    // ── Logs ──
    Route::get('/activity-logs', [AuthController::class, 'getActivityLogs']);
    Route::get('/activity-logs/{subject_type}', [AuthController::class, 'getLogsByType']);

    // ── Certificats admin ──
    Route::get('/admin/certificats', [CertificatDePerteController::class, 'index']);

    // ── Publications admin ──
    Route::get('all-publications', [DocumentController::class, 'getAllPublications']);
    Route::get('publications-by-type', [DocumentController::class, 'getPublicationsByType']);
    Route::get('restitution-data', [DocumentController::class, 'getRestitutionData']);
    Route::get('email-activity', [DocumentController::class, 'getEmailActivity']);
    Route::get('statistics', [DocumentController::class, 'getStatistics']);
    Route::get('status-count', [DocumentController::class, 'getDocumentStatusCountWithTrashed']);
    Route::get('deleted-documents', [DocumentController::class, 'getDeletedDocuments']);
    Route::get('recovered-documents', [DocumentController::class, 'getRecoveredDocuments']);
    Route::get('not-recovered-documents', [DocumentController::class, 'getNotRecoveredDocuments']);

    // ── Types de documents : écriture ──
    Route::post('document-types', [DocumentTypeController::class, 'store']);
    Route::match(['put', 'patch'], 'document-types/{document_type}', [DocumentTypeController::class, 'update']);
    Route::delete('document-types/{document_type}', [DocumentTypeController::class, 'destroy']);

    // ── Backup ──
    Route::post('/backup/run', [BackupController::class, 'runBackup']);
    Route::get('/backup/list', [BackupController::class, 'listBackups']);
    Route::get('/backup/status', [BackupController::class, 'backupStatus']);
    Route::post('/backup/clean', [BackupController::class, 'cleanBackups']);
    Route::get('/backup/download', [BackupController::class, 'downloadBackup']);

    // ── Rapports ──
    Route::get('/rapports', [RapportController::class, 'index']);
    Route::get('/rapports/stats', [RapportController::class, 'stats']);
    Route::post('/rapports/generer', [RapportController::class, 'generer']);
    Route::get('/rapports/apercu', [RapportController::class, 'apercu']);
    Route::get('/rapports/{uuid}/voir', [RapportController::class, 'voir']);
    Route::get('/rapports/{uuid}/telecharger', [RapportController::class, 'telecharger']);
    Route::delete('/rapports/{uuid}', [RapportController::class, 'destroy']);
});

// ══════════════════════════════════════════════════════════════════
// API V2 — espace institutionnel
// ══════════════════════════════════════════════════════════════════
Route::prefix('v2')->group(base_path('routes/api_v2.php'));

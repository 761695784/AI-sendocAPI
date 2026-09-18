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
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RapportController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES SENSIBLES — throttle strict
// 10 tentatives par minute (anti brute-force)
// ══════════════════════════════════════════════════════════════════
Route::middleware('throttle:10,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::get('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/verify-email', [EmailVerificationController::class, 'verify']);
});

// ══════════════════════════════════════════════════════════════════
// ROUTES PUBLIQUES LECTURE — throttle souple
// 120 requêtes par minute (navigation normale)
// ══════════════════════════════════════════════════════════════════
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleLogin']);
    Route::post('/auth/google/finalize-account-creation', [AuthController::class, 'finalizeAccountCreation']);
    Route::post('/found-qr', [QrCodeController::class, 'handleFoundQr']);

    // Documents publics
    Route::get('documents', [DocumentController::class, 'index']);
    Route::get('documents/{slug}', [DocumentController::class, 'show']);
    Route::get('documents/{document_slug}/comments', [CommentaireController::class, 'getCommentairesByDocument']);
    Route::get('lieu', [DocumentController::class, 'getPublicationsByLocation']);
    Route::apiResource('document', DocumentController::class);
});

// ══════════════════════════════════════════════════════════════════
// ROUTES PROTÉGÉES — authentification + throttle modéré
// 60 requêtes par minute par utilisateur connecté
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {

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

    // ── Commentaires ──
    Route::apiResource('comments', CommentaireController::class);

    // ── Notifications ──
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{slug}/mark-as-read', [NotificationController::class, 'markAsRead']);
    Route::get('correspondence-emails', [NotificationController::class, 'showAllCorrespondenceEmails']);
    Route::get('restitution-emails', [NotificationController::class, 'showAllRestitutionEmails']);
    Route::get('all-emails', [NotificationController::class, 'showAllEmails']);
    Route::get('all-notifications', [NotificationController::class, 'getAllData']);
    Route::get('new-notifications', [NotificationController::class, 'getNewNotifications']);
    Route::get('restitution-count', [NotificationController::class, 'getRestitutionRequestCount']);
});

// ══════════════════════════════════════════════════════════════════
// ROUTES ADMIN — throttle généreux (200/min)
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth:api', 'throttle:200,1'])->group(function () {

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
// TYPES DE DOCUMENTS
// ══════════════════════════════════════════════════════════════════
Route::apiResource('document-types', DocumentTypeController::class)
    ->middleware(['auth:api', 'throttle:60,1']);

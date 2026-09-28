<?php

use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\V2\CitizenProfileController;
use App\Http\Controllers\V2\CollectionController;
use App\Http\Controllers\V2\CounterDeclarationController;
use App\Http\Controllers\V2\FoundDocumentController;
use App\Http\Controllers\V2\InboxController;
use App\Http\Controllers\V2\NotificationDeliveryController;
use App\Http\Controllers\V2\MatchController;
use App\Http\Controllers\V2\MeController;
use App\Http\Controllers\V2\MembershipController;
use App\Http\Controllers\V2\OrganizationController;
use App\Http\Controllers\V2\OrganizationTypeController;
use App\Http\Controllers\V2\TerritoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V2 — préfixe /api/v2
|--------------------------------------------------------------------------
| Espace institutionnel : organisations, agents, rôles par organisation.
| L'en-tête optionnel "X-Organization: <uuid>" indique au nom de quelle
| structure l'agent agit (sinon : son affectation principale).
*/

// Vérification publique d'un certificat (sans connexion).
Route::get('verify/{code}', [CertificateVerificationController::class, 'api'])
    ->middleware('throttle:30,1')
    ->where('code', '[A-Za-z0-9\-]{4,40}');

Route::middleware(['auth:api', 'active', 'organization', 'throttle:120,1'])->group(function () {

    Route::get('me/organizations', [MeController::class, 'organizations']);

    // Référentiels
    Route::get('territories', [TerritoryController::class, 'index']);
    Route::get('organization-types', [OrganizationTypeController::class, 'index']);

    // Organisations (le périmètre est filtré selon les droits)
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::match(['put', 'patch'], 'organizations/{organization}', [OrganizationController::class, 'update']);
    Route::post('organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate']);
    Route::post('organizations/{organization}/activate', [OrganizationController::class, 'activate']);

    // Agents d'une organisation
    Route::get('organizations/{organization}/members', [MembershipController::class, 'index']);
    Route::post('organizations/{organization}/members', [MembershipController::class, 'store']);
    Route::match(['put', 'patch'], 'memberships/{membership}', [MembershipController::class, 'update']);
    Route::post('memberships/{membership}/end', [MembershipController::class, 'end']);

    // Guichet : victimes et déclarations
    Route::get('citizens', [CitizenProfileController::class, 'search']);
    Route::post('citizens', [CitizenProfileController::class, 'store']);
    Route::get('declarations', [CounterDeclarationController::class, 'index']);
    Route::post('declarations', [CounterDeclarationController::class, 'store']);
    Route::get('declarations/{declaration}', [CounterDeclarationController::class, 'show']);
    Route::post('declarations/{declaration}/cancel', [CounterDeclarationController::class, 'cancel']);
    Route::get('declarations/{declaration}/certificate', [CounterDeclarationController::class, 'certificate']);

    // Documents trouvés en garde
    Route::get('found-documents', [FoundDocumentController::class, 'index']);
    Route::post('found-documents', [FoundDocumentController::class, 'store']);
    Route::get('found-documents/{document}', [FoundDocumentController::class, 'show']);
    Route::post('found-documents/{document}/take-custody', [FoundDocumentController::class, 'takeCustody']);

    // Collecte des documents publiés par les citoyens (structure la plus proche)
    Route::get('collections', [CollectionController::class, 'index']);
    Route::get('collections/{collection}', [CollectionController::class, 'show']);
    Route::post('collections/{collection}/contact', [CollectionController::class, 'contact']);
    Route::post('collections/{collection}/plan', [CollectionController::class, 'plan']);
    Route::post('collections/{collection}/collected', [CollectionController::class, 'collected']);
    Route::post('collections/{collection}/transfer', [CollectionController::class, 'transfer']);
    Route::post('collections/{collection}/decline', [CollectionController::class, 'decline']);
    Route::post('collections/{collection}/cancel', [CollectionController::class, 'cancel']);

    // Correspondances : revue par les agents
    Route::get('matches', [MatchController::class, 'index']);
    Route::get('matches/{match}', [MatchController::class, 'show']);
    Route::post('matches/{match}/confirm', [MatchController::class, 'confirm']);
    Route::post('matches/{match}/reject', [MatchController::class, 'reject']);

    // Boîte de réception de l'agent
    Route::get('inbox', [InboxController::class, 'index']);
    Route::get('inbox/unread-count', [InboxController::class, 'unreadCount']);
    Route::post('inbox/read-all', [InboxController::class, 'markAllRead']);
    Route::post('inbox/{message}/read', [InboxController::class, 'markRead']);

    // Suivi des envois (SMS, emails, boîte de réception)
    Route::get('notification-deliveries', [NotificationDeliveryController::class, 'index']);
    Route::get('notification-deliveries/summary', [NotificationDeliveryController::class, 'summary']);
    Route::post('notification-deliveries/{delivery}/retry', [NotificationDeliveryController::class, 'retry']);

    // Types d'organisation : super_admin
    Route::middleware('role:super_admin,api')->group(function () {
        Route::post('organization-types', [OrganizationTypeController::class, 'store']);
        Route::match(['put', 'patch'], 'organization-types/{organizationType:code}', [OrganizationTypeController::class, 'update']);
        Route::delete('organization-types/{organizationType:code}', [OrganizationTypeController::class, 'destroy']);
    });
});

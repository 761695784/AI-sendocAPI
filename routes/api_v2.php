<?php

use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\TagScanController;
use App\Http\Controllers\V2\ActivityReportController;
use App\Http\Controllers\V2\AssistantController;
use App\Http\Controllers\V2\AssistantVoiceController;
use App\Http\Controllers\V2\OcrController;
use App\Http\Controllers\V2\BillingController;
use App\Http\Controllers\V2\ChargeController;
use App\Http\Controllers\V2\CitizenProfileController;
use App\Http\Controllers\V2\CollectionController;
use App\Http\Controllers\V2\CounterDeclarationController;
use App\Http\Controllers\V2\FoundDocumentController;
use App\Http\Controllers\V2\InboxController;
use App\Http\Controllers\V2\NotificationDeliveryController;
use App\Http\Controllers\V2\MatchController;
use App\Http\Controllers\V2\MeController;
use App\Http\Controllers\V2\MembershipController;
use App\Http\Controllers\V2\OnlineDeclarationController;
use App\Http\Controllers\V2\OrganizationController;
use App\Http\Controllers\V2\OrganizationTypeController;
use App\Http\Controllers\V2\RestitutionController;
use App\Http\Controllers\V2\StatsController;
use App\Http\Controllers\V2\TerritoryController;
use App\Http\Controllers\V2\VigilanceController;
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

// Scan public d'un QR de document (trouveur sans compte).
Route::get('tags/{token}', [TagScanController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{20,40}');
Route::post('tags/{token}/found', [TagScanController::class, 'report'])->middleware('throttle:5,1')->where('token', '[A-Za-z0-9]{20,40}');

// Assistant conversationnel (texte + voix, français + wolof) : ouvert aux
// visiteurs pour l'information ; le jeton JWT, s'il est fourni, identifie le citoyen.
Route::prefix('assistant')->group(function () {
    Route::get('capabilities', [AssistantController::class, 'capabilities'])->middleware('throttle:60,1');
    Route::post('messages', [AssistantController::class, 'message'])->middleware('throttle:30,1');
    Route::post('voice', [AssistantController::class, 'voice'])->middleware('throttle:10,1');
    Route::get('conversations/{conversation}', [AssistantController::class, 'show'])->middleware('throttle:60,1');
    Route::get('audio/{lang}/{key}', [AssistantController::class, 'audio'])
        ->middleware('throttle:120,1')->where(['lang' => '[a-z]{2}', 'key' => '[a-z0-9_]{2,60}']);
});

// Scan OCR d'une pièce trouvée (citoyen connecté) : lecture, champs proposés, photo publique masquée.
Route::middleware(['auth:api', 'active'])->prefix('ocr')->group(function () {
    Route::post('scan', [OcrController::class, 'scan'])->middleware('throttle:10,1');
    Route::get('scans/{scan}', [OcrController::class, 'show'])->middleware('throttle:60,1');
});
Route::get('ocr/scans/{scan}/preview', [OcrController::class, 'preview'])
    ->middleware(['signed', 'throttle:60,1'])->name('ocr.preview');

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
    Route::get('citizens', [CitizenProfileController::class, 'search'])->middleware('throttle:counter-search');
    Route::post('citizens', [CitizenProfileController::class, 'store'])->middleware('throttle:30,1');
    Route::post('citizens/{citizen}/account', [CitizenProfileController::class, 'account'])->middleware('throttle:10,1');
    Route::get('declarations', [CounterDeclarationController::class, 'index']);
    Route::post('declarations', [CounterDeclarationController::class, 'store']);
    Route::get('declarations/{declaration}', [CounterDeclarationController::class, 'show']);
    Route::post('declarations/{declaration}/cancel', [CounterDeclarationController::class, 'cancel']);
    Route::get('declarations/{declaration}/certificate', [CounterDeclarationController::class, 'certificate']);

    // Guichet : certifier une déclaration faite en ligne (référence ou QR du récépissé)
    Route::get('online-declarations/lookup', [OnlineDeclarationController::class, 'lookup'])->middleware('throttle:counter-search');
    Route::post('online-declarations/{declaration}/certify', [OnlineDeclarationController::class, 'certify'])->middleware('throttle:20,1');

    // Documents trouvés en garde
    Route::get('found-documents', [FoundDocumentController::class, 'index']);
    Route::post('found-documents', [FoundDocumentController::class, 'store']);
    Route::get('found-documents/{document}', [FoundDocumentController::class, 'show']);
    Route::post('found-documents/{document}/take-custody', [FoundDocumentController::class, 'takeCustody']);

    // Restitution officielle au guichet
    Route::get('found-documents/{document}/restitution', [RestitutionController::class, 'prepare']);
    Route::post('found-documents/{document}/restitution', [RestitutionController::class, 'store']);
    Route::get('restitutions', [RestitutionController::class, 'index']);
    Route::get('restitutions/{restitution}', [RestitutionController::class, 'show']);
    Route::get('restitutions/{restitution}/discharge', [RestitutionController::class, 'discharge']);
    Route::post('restitutions/{restitution}/signed-scan', [RestitutionController::class, 'signedScan']);
    Route::post('restitutions/{restitution}/void', [RestitutionController::class, 'void']);

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

    // Frais au guichet (encaissement, exonération, lien Wave)
    Route::get('charges', [ChargeController::class, 'index']);
    Route::get('charges/{charge}', [ChargeController::class, 'show']);
    Route::post('charges/{charge}/cash', [ChargeController::class, 'cash']);
    Route::post('charges/{charge}/exempt', [ChargeController::class, 'exempt']);
    Route::post('charges/{charge}/cancel', [ChargeController::class, 'cancel']);
    Route::post('charges/{charge}/checkout', [ChargeController::class, 'checkout']);

    // Tarifs (super admin) et rapport de reversement
    Route::get('billing/services', [BillingController::class, 'services']);
    Route::post('billing/prices', [BillingController::class, 'setPrice']);
    Route::get('billing/report', [BillingController::class, 'report']);

    // Rapports d'activité (PDF + Excel) par périmètre
    // Vigilance : doublons et signaux de fraude (relus par les responsables)
    Route::get('vigilance/alerts', [VigilanceController::class, 'index']);
    Route::get('vigilance/alerts/{alert}', [VigilanceController::class, 'show']);
    Route::post('vigilance/alerts/{alert}/dismiss', [VigilanceController::class, 'dismiss']);
    Route::post('vigilance/alerts/{alert}/confirm', [VigilanceController::class, 'confirm']);

    Route::get('reports', [ActivityReportController::class, 'index']);
    Route::post('reports', [ActivityReportController::class, 'store'])->middleware('throttle:10,1');
    Route::get('reports/{report}', [ActivityReportController::class, 'show']);
    Route::get('reports/{report}/pdf', [ActivityReportController::class, 'pdf']);
    Route::get('reports/{report}/xlsx', [ActivityReportController::class, 'xlsx']);
    Route::delete('reports/{report}', [ActivityReportController::class, 'destroy']);
    Route::post('reports/{report}/narrative/approve', [ActivityReportController::class, 'approveNarrative']);
    Route::post('reports/{report}/narrative/reject', [ActivityReportController::class, 'rejectNarrative']);
    Route::post('reports/{report}/narrative/regenerate', [ActivityReportController::class, 'regenerateNarrative'])->middleware('throttle:10,1');

    // Statistiques par périmètre (?organization=uuid&from=&to=)
    Route::get('stats/overview', [StatsController::class, 'overview']);
    Route::get('stats/structures', [StatsController::class, 'structures']);
    Route::get('stats/timeseries', [StatsController::class, 'timeseries']);
    Route::get('stats/overdue-collections', [StatsController::class, 'overdueCollections']);

    // Types d'organisation : super_admin
    Route::middleware('role:super_admin,api')->group(function () {
        Route::post('organization-types', [OrganizationTypeController::class, 'store']);

        // Voix de l'assistant : phrases lues une fois par le modèle de synthèse (Adia_TTS, wolof) puis servies en MP3.
        Route::get('assistant/voices', [AssistantVoiceController::class, 'index']);
        Route::post('assistant/voices/{lang}/{key}', [AssistantVoiceController::class, 'generate'])
            ->middleware('throttle:30,1')->where(['lang' => '[a-z]{2}', 'key' => '[a-z0-9_]{2,60}']);
        Route::delete('assistant/voices/{lang}/{key}', [AssistantVoiceController::class, 'destroy'])
            ->where(['lang' => '[a-z]{2}', 'key' => '[a-z0-9_]{2,60}']);
        Route::match(['put', 'patch'], 'organization-types/{organizationType:code}', [OrganizationTypeController::class, 'update']);
        Route::delete('organization-types/{organizationType:code}', [OrganizationTypeController::class, 'destroy']);
    });
});

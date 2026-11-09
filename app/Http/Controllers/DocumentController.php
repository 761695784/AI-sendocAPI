<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\MatchDocumentJob;
use App\Services\Collections\CollectionService;
use App\Jobs\NotifyDeclarantJob;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\RestitutionRequestNotification;
use App\Services\EmailNotificationService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentController extends Controller
{

    /** Filtre des éléments supprimés pour l'administration : without (défaut), only, with. */
    public static function applyTrashedFilter($query, ?string $mode)
    {
        return match ($mode) {
            'only'  => $query->onlyTrashed(),
            'with'  => $query->withTrashed(),
            default => $query,
        };
    }

    public static function frontendDocumentUrl(Document $document): string
    {
        return rtrim(config('doctrack.frontend_url'), '/') . '/document/' . $document->uuid;
    }

    // ══════════════════════════════════════════════════════════════
    // INDEX — Liste publique des documents
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $user = Auth::user();
        // Taille de page demandée par l'écran public (20 par défaut, 100 au plus).
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        // Liste publique : jamais les publications supprimées, même pour l'administrateur
        // (il les retrouve dans l'administration, filtre « Supprimées »).
        $documents = Document::query()
            // Les signalements par QR ne sont pas publiés : le propriétaire est prévenu directement.
            ->where(fn ($q) => $q->whereNull('found_channel')->orWhere('found_channel', '!=', Document::CHANNEL_QR_SCAN))
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate($perPage);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // Afficher toutes les publications y compris celles supprimer en soft delete — Admin seulement
    // ══════════════════════════════════════════════════════════════
    public function getAllPublications(Request $request)
    {
        if (! Auth::user()?->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
        }

        // Par défaut, seulement les publications en ligne ; ?trashed=only pour la corbeille, ?trashed=with pour tout.
        $documents = self::applyTrashedFilter(Document::query(), $request->query('trashed'))
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(max(1, min(100, (int) $request->query('per_page', 20))));

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // STORE — Créer une publication
    // ════════════════════════════════════════════════════════

    public function store(StoreDocumentRequest $request, EmailNotificationService $emailService, SmsService $smsService)
    {
        $validatedData = $request->validated();

        // Envoi préparé hors connexion et déjà reçu (nouvelle tentative de l'appareil) : pas de doublon.
        if ($existing = $this->publicationAlreadyReceived($validatedData['client_uuid'] ?? null)) {
            return $existing;
        }

        // Pièce scannée juste avant : la photo vient du scan (déjà lue et masquée).
        $scan = ! empty($validatedData['ocr_scan_id'])
            ? \App\Models\OcrScan::where('uuid', $validatedData['ocr_scan_id'])->where('user_id', Auth::id())->first()
            : null;

        // ── Vérifie l'image AVANT de créer le document ──
        if (! $scan && (! $request->hasFile('image') || ! $request->file('image')->isValid())) {
            return response()->json(['error' => 'Aucun fichier image valide fourni'], 400);
        }

        // ── Crée le document SANS image ──
        try {
            $document = Document::create(\App\Support\OfflineSubmission::columns($validatedData) + [
                'OwnerFirstName'    => $validatedData['OwnerFirstName'],
                'OwnerLastName'     => $validatedData['OwnerLastName'],
                'Location'          => $validatedData['Location'],
                'statut'            => 'non récupéré',
                'document_type_id'  => $validatedData['document_type_id'],
                'user_id'           => Auth::id(),
                'DocIdentification' => $validatedData['DocIdentification'] ?? null,
                'found_place'       => $validatedData['Location'],
                'found_latitude'    => $validatedData['latitude'] ?? null,
                'found_longitude'   => $validatedData['longitude'] ?? null,
                'territory_id'      => ! empty($validatedData['territory_code'])
                    ? \App\Models\Territory::where('code', $validatedData['territory_code'])->value('id')
                    : null,
                'contact_consent_at' => $request->boolean('contact_consent') ? now() : null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Deux envois identiques arrivés en même temps : le premier a gagné.
            return $this->publicationAlreadyReceived($validatedData['client_uuid'] ?? null) ?? throw $e;
        }

        // ── Upload + conversions via MediaLibrary ── génère automatiquement : thumb, blurred, optimized en WebP
        $ocr = app(\App\Services\Ocr\OcrScanService::class);
        if ($scan) {
            // Original privé + copie masquée pour le public ; numéros supplémentaires pour les correspondances.
            $ocr->attachToDocument($scan, $document, $validatedData);
        } else {
            // Photo envoyée directement : masquage automatique si le service OCR est branché (AI_OCR_MASK_ON_UPLOAD).
            $masked = $ocr->maskUploadedImage($request->file('image'), $document, Auth::user());
            $document->addMediaFromRequest('image')
                ->withCustomProperties($masked ? ['public_version' => 'masked'] : [])
                ->toMediaCollection('document_image');
        }

        // ── Log d'activité ──
        activity()
            ->causedBy(Auth::user())
            ->performedOn($document)
            ->withProperties([
                'OwnerFirstName'   => $document->OwnerFirstName,
                'OwnerLastName'    => $document->OwnerLastName,
                'document_type_id' => $document->document_type_id,
                'Location'         => $document->Location,
            ])
            ->log('Document publié');

        // ── Notifications ──
            $declarations = DeclarationDePerte::whereRaw(
                'LOWER(FirstNameInDoc) = ?', [strtolower($document->OwnerFirstName)]
            )
            ->whereRaw(
                'LOWER(LastNameInDoc) = ?', [strtolower($document->OwnerLastName)]
            )
            ->where('document_type_id', $document->document_type_id)
            ->where('user_id', '!=', $document->user_id) // pas de notification à soi-même
            ->with('user')
            ->get();

        foreach ($declarations as $declaration) {
            dispatch(new NotifyDeclarantJob(
                $document,
                $declaration->user,
                self::frontendDocumentUrl($document),
                $declaration->id
            ));
        }

        // Moteur de correspondance V2 (scores, revue par les agents).
        MatchDocumentJob::dispatch($document->id);

        // Le trouveur accepte d'être contacté : la structure la plus proche organise la collecte.
        $collection = null;
        if ($request->boolean('contact_consent')) {
            $collection = app(CollectionService::class)->open(
                $document->fresh(),
                Auth::user(),
                $validatedData['contact_phone'] ?? Auth::user()->Phone,
            );
        }

        // Invalide le cache des stats après ajout
        Cache::forget('statistics_month_' . now()->format('Y_m'));
        Cache::forget('publications_by_type');
        Cache::forget('publications_by_department');

        return response()->json([
            'success'  => true,
            'message'  => $collection?->organization
                ? 'Document publié. ' . $collection->organization->name . ' va vous contacter pour le récupérer.'
                : 'Document créé avec succès.',
            'document'   => new DocumentResource($document->fresh(['user', 'documentType', 'custodyOrganization'])),
            'collection' => $collection ? self::collectionSummary($collection) : null,
        ], 201);
    }

    /** Réponse identique à la publication, pour un envoi hors connexion déjà enregistré. */
    private function publicationAlreadyReceived(?string $clientUuid): ?\Illuminate\Http\JsonResponse
    {
        if (! $clientUuid) {
            return null;
        }
        $document = Document::where('user_id', Auth::id())->where('client_uuid', $clientUuid)->first();
        if (! $document) {
            return null;
        }
        $collection = $document->collectionRequests()->latest('id')->first();

        return response()->json([
            'success'    => true,
            'replayed'   => true,
            'message'    => 'Cette publication avait déjà été reçue.',
            'document'   => new DocumentResource($document->fresh(['user', 'documentType', 'custodyOrganization'])),
            'collection' => $collection ? self::collectionSummary($collection) : null,
        ], 200);
    }

    /**
     * Gérer la demande de restitution.
     */
    public function requestRestitution(Request $request, $uuid, \App\Services\Restitution\DirectRestitutionService $restitutions)
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $fromUser = Auth::user();

        if ($fromUser->id === $document->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas demander la restitution de votre propre document.',
            ], 403);
        }

        if ($document->found_channel === Document::CHANNEL_QR_SCAN) {
            return response()->json([
                'success' => false,
                'message' => 'Ce document a été signalé grâce à son QR code : son propriétaire a été prévenu directement.',
            ], 409);
        }

        if ($document->statut === 'récupéré' || $document->lifecycle_status === Document::LIFECYCLE_RESTITUTED) {
            return response()->json(['success' => false, 'message' => 'Ce document a déjà été restitué.'], 409);
        }

        if ($active = $restitutions->activeRequest($document)) {
            return response()->json([
                'success' => false,
                'message' => $active->requester_user_id === $fromUser->id
                    ? 'Vous avez déjà une demande en cours pour ce document : retrouvez-la dans « Mon espace → Remises ».'
                    : 'Une demande de restitution est déjà en cours pour ce document.',
                'request' => ['uuid' => $active->uuid, 'status' => $active->status, 'channel' => $active->channel],
            ], 400);
        }

        // ── Cas 2 : document conservé par une structure → retrait au guichet ──
        if ($document->found_channel === Document::CHANNEL_COUNTER || $document->isInCustody()) {
            $structure = $document->custodyOrganization;
            if (! $structure) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce document est conservé par une structure officielle. Présentez-vous avec une pièce justificative et la référence ' . $document->reference . '.',
                ], 409);
            }

            $req = $restitutions->requestAtStructure($document, $fromUser, $structure);

            return response()->json([
                'success'   => true,
                'channel'   => 'counter',
                'message'   => $structure->name . ' a été prévenu(e) de votre demande. Présentez-vous avec une pièce d\'identité et la référence '
                    . $document->reference . ' : l\'agent vérifiera votre identité et vous remettra le document contre une décharge.',
                'custody'   => $structure->only(['uuid', 'name', 'address', 'phone']),
                'reference' => $document->reference,
                'request'   => ['uuid' => $req->uuid, 'status' => $req->status, 'channel' => $req->channel],
            ], 201);
        }

        // ── Cas 1 : document publié par un citoyen → mise en relation directe ──
        $data = $request->validate([
            'accept_terms' => 'accepted',
            'doc_last4'    => 'nullable|string|max:20',
            'ask_finder'   => 'nullable|boolean', // identité non prouvée : laisser le trouveur décider
        ], [
            'accept_terms.accepted' => 'Vous devez accepter les conditions de la remise directe pour continuer.',
        ]);

        $match = $restitutions->confirmedMatchFor($document, $fromUser);

        // 4 caractères du numéro : 3 essais par personne et par document (24 h), sinon on pourrait les deviner.
        $last4     = $data['doc_last4'] ?? null;
        $guessKey  = 'restitution-last4:' . $fromUser->id . ':' . $document->id;
        $tooMany   = \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($guessKey, 3);
        if ($tooMany) {
            $last4 = null;
        }

        $proven = $restitutions->ownershipLooksRight($document, $fromUser, $last4);
        if (! $proven && $last4 !== null && $restitutions->knownLast4($document) !== null) {
            \Illuminate\Support\Facades\RateLimiter::hit($guessKey, 86400);
            activity()->causedBy($fromUser)->performedOn($document)
                ->withProperties(['ip' => $request->ip()])
                ->log('Restitution : 4 caractères du numéro incorrects');
            $tooMany = \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($guessKey, 3);
        }

        if (! $proven) {
            // Identité non prouvée : le trouveur, qui a le document en main, décide. Un nom de compte
            // concordant envoie directement la demande au trouveur (le nom seul ne prouve rien).
            if ($request->boolean('ask_finder') || ($last4 === null && ! $tooMany && $restitutions->nameMatches($document, $fromUser))) {
                $req = $restitutions->requestToFinder($document, $fromUser);

                return response()->json([
                    'success' => true,
                    'channel' => 'direct',
                    'message' => 'Votre demande est envoyée au trouveur : il compare avec le document puis accepte ou refuse. '
                        . 'S\'il accepte, vous recevrez son numéro par SMS. Sans réponse sous 48 h, une structure proche prend le relais.',
                    'finder'  => null,
                    'request' => ['uuid' => $req->uuid, 'status' => $req->status, 'channel' => $req->channel],
                ], 201);
            }

            $hasNumber = $restitutions->knownLast4($document) !== null && ! $tooMany;

            return response()->json([
                'success'    => false,
                'code'       => 'identity_check',
                'has_number' => $hasNumber,
                'locked'     => $tooMany,
                'message'    => $tooMany
                    ? 'Trop d\'essais pour les 4 caractères du numéro. Envoyez votre demande au trouveur : il compare avec le document qu\'il a en main.'
                    : 'Le nom de votre compte ne correspond pas au nom inscrit sur ce document. '
                    . ($hasNumber
                        ? 'Indiquez les 4 derniers caractères de son numéro, ou envoyez votre demande au trouveur pour qu\'il vérifie.'
                        : 'Le trouveur n\'a pas saisi le numéro de la pièce : envoyez votre demande au trouveur pour qu\'il vérifie.'),
            ], 403);
        }

        $req    = $restitutions->requestDirect($document, $fromUser, $match);
        $finder = $document->user;

        return response()->json([
            'success' => true,
            'channel' => 'direct',
            'message' => 'Demande envoyée. Le trouveur a reçu votre numéro par SMS et par email. Vous pouvez aussi l\'appeler.',
            'finder'  => [
                'first_name' => $finder?->FirstName,
                'phone'      => $finder ? ($finder->Phone ?: $document->collectionRequests()->latest('id')->first()?->finderPhone()) : null,
            ],
            'request' => ['uuid' => $req->uuid, 'status' => $req->status, 'channel' => $req->channel],
        ], 201);
    }

    /**
     * Afficher le document specifique.
     */
    public function show($uuid)
    {
        $document = Document::with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_if($document->found_channel === Document::CHANNEL_QR_SCAN && ! Auth::user()?->isSuperAdmin(), 404);

        return new DocumentResource($document);
    }

    /**
     * Mise à jour de document
     */
    public function update(UpdateDocumentRequest $request, $uuid)
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();

        if (Auth::id() !== $document->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à mettre à jour ce document.',
            ], 403);
        }

        if ($document->found_channel === Document::CHANNEL_COUNTER) {
            return self::counterDocumentLocked();
        }

        $validatedData = $request->validated();

        // L'image n'est pas une colonne : elle passe par MediaLibrary.
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $document->addMediaFromRequest('image')->toMediaCollection('document_image');
        }
        unset($validatedData['image']);

        $document->update($validatedData);

        // Cycle de vie V2 aligné sur le statut V1 déclaré par le publicateur
        // (remise directe entre particuliers, « déclarée par les parties »).
        if ($document->statut === 'récupéré' && $document->lifecycle_status !== Document::LIFECYCLE_RESTITUTED) {
            $document->moveTo(Document::LIFECYCLE_RESTITUTED, 'Restitution déclarée par les parties', Auth::user());
            app(\App\Services\Matching\MatchReviewService::class)->supersedeForDocument($document, Auth::user(), 'Document restitué');
            app(\App\Services\Matching\MatchReviewService::class)->applyRestitution($document, Auth::user(), 'Restitution déclarée par les parties');
            app(\App\Services\Restitution\DirectRestitutionService::class)->completeForDocument($document, Auth::user());
            app(CollectionService::class)->cancelForDocument($document, Auth::user(), 'Document remis directement par le trouveur');
        } elseif ($document->statut === 'non récupéré' && $document->lifecycle_status === Document::LIFECYCLE_RESTITUTED) {
            $document->moveTo(Document::LIFECYCLE_PUBLISHED, 'Restitution annulée par le publicateur', Auth::user());
        }

        // Noms ou numéro corrigés : on recalcule les correspondances ouvertes.
        MatchDocumentJob::dispatch($document->id);

        // Invalide le cache si le statut change
        Cache::forget('statistics_month_' . now()->format('Y_m'));

        // ── Log d'activité ──
        activity()
            ->causedBy(Auth::user())
            ->performedOn($document)
            ->withProperties(collect($validatedData)->except('DocIdentification')->all())
            ->log('Document mis à jour');

        return response()->json([
            'success'  => true,
            'message'  => 'Document mis à jour avec succès.',
            'document' => new DocumentResource($document->load(['user', 'documentType'])),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Suppression douce
    // ══════════════════════════════════════════════════════════════
    public function destroy($uuid)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        // BUG CORRIGÉ : était findOrFail($uuid) ce qui cherchait par ID
        $document = Document::where('uuid', $uuid)->firstOrFail();

        if ($document->found_channel === Document::CHANNEL_COUNTER && ! $user->isSuperAdmin()) {
            return self::counterDocumentLocked();
        }

        if ($user->isSuperAdmin() || $document->user_id === $user->id) {

            // ── Log d'activité ──
            /** @var \App\Models\User $user */
            $user = Auth::user();
            activity()
                ->causedBy($user)
                ->performedOn($document)
                ->withProperties([
                    'document_uuid' => $document->uuid,
                    'deleted_by'    => $user->id,
                ])
                ->log('Document supprimé');

            $document->delete();

            // Invalide le cache
            Cache::forget('publications_by_type');
            Cache::forget('publications_by_department');

            return response()->json([
                'success' => true,
                'message' => 'Publication supprimée avec succès.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez supprimer que vos propres publications.',
        ], 403);
    }

                                                  // Fonction de la restauration d'un document supprimé
    public function restoreTrashedDocument($uuid) // Changé de $id à $uuid
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        $document = Document::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        if ($document->user_id === $user->id) {
            // ── Log d'activité ──
            /** @var \App\Models\User $user */
            $user = Auth::user();
            activity()
                ->causedBy($user)
                ->performedOn($document)
                ->withProperties([
                    'document_uuid' => $document->uuid,
                    'restored_by'   => $user->id,
                ])
                ->log('Document restauré');
            $document->restore();
            return response()->json([
                'success' => true,
                'message' => 'Document restauré avec succès.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez restaurer que vos propres documents.',
        ], 403);
    }

    // ══════════════════════════════════════════════════════════════
    // TRASHED — Documents supprimés de l'utilisateur connecté
    // ══════════════════════════════════════════════════════════════
    public function trashedDocuments()
    {
        $user = Auth::user(); // Récupérer l'utilisateur authentifié

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401); // Code 401 Unauthorized
        }

        // Récupérer uniquement les documents supprimés de l'utilisateur connecté
        $documents = Document::onlyTrashed()
            ->where('user_id', $user->id)
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->limit(100)
            ->get();

        // Liste simple (et non une page imbriquée) : le front l'affiche directement dans la corbeille.
        return response()->json([
            'success' => true,
            'data'    => DocumentResource::collection($documents)->resolve(),
        ]);
    }

    // Fonction pour obtenir uniquement les documents de l'utilisateur connecté
    public function OwnPub()
    {
        $documents = Document::where('user_id', Auth::id())
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(20);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // ADMIN — Documents supprimés
    // ══════════════════════════════════════════════════════════════
    public function getDeletedDocuments()
    {
        $user = Auth::user(); // Récupérer l'utilisateur authentifié

        // Vérifier si l'utilisateur est un admin
        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer uniquement les documents supprimés (soft deleted)
        $documents = Document::onlyTrashed()->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(20);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // ADMIN — Documents récupérés
    // ══════════════════════════════════════════════════════════════
    public function getRecoveredDocuments()
    {
        $user = Auth::user(); // Récupérer l'utilisateur authentifié

        // Vérifier si l'utilisateur est un admin
        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer les documents dont le statut est "récupéré", y compris ceux qui sont soft-deleted
        $documents = Document::withTrashed()
            ->where('statut', 'récupéré')
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(20);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // ADMIN — Documents non récupérés
    // ══════════════════════════════════════════════════════════════
    public function getNotRecoveredDocuments()
    {
        $user = Auth::user(); // Récupérer l'utilisateur authentifié

        // Vérifier si l'utilisateur est un admin
        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer les documents dont le statut est "non récupéré", y compris ceux qui sont soft-deleted
        $documents = Document::withTrashed()
            ->where('statut', 'non récupéré')
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(20);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // STATISTIQUES — avec cache
    // ══════════════════════════════════════════════════════════════

    // Fonction pour obtenir les publications par type
    public function getPublicationsByType()
    {
        $data = Cache::remember('publications_by_type', 1800, function () {
            return Document::select('document_type_id', DB::raw('count(*) as count'))
                ->groupBy('document_type_id')
                ->with('documentType')
                ->get()
                ->map(fn($p) => [
                    'typeName' => $p->documentType->TypeName,
                    'count'    => $p->count,
                ]);
        });

        return response()->json(['data' => $data]);
    }

    // Fonction pour obtenir les données de restitution
    public function getRestitutionData()
    {
        $data = Cache::remember('restitution_data', 900, function () {
            return [
                'restitutionCount' => EmailLog::where(
                    'subject', 'LIKE', '%Demande de restitution%'
                )->count(),
                'publicationCount' => Document::withTrashed()->count(),
            ];
        });

        return response()->json($data);
    }

    // Fonction pour obtenir les données d'activité des emails
    public function getEmailActivity()
    {
        $data = Cache::remember('email_activity', 900, function () {
            return EmailLog::select('subject', DB::raw('count(*) as count'))
                ->groupBy('subject')
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Fonction pour obtenir les statistiques de publications et déclarations de perte par mois
     */
    public function getStatistics()
    {
        // Créer une clé de cache unique pour les statistiques mensuelles
        $cacheKey = 'statistics_month_' . now()->format('Y_m');
        // Récupérer les données par date avec mise en cache
        $data = Cache::remember($cacheKey, 3600, function () {
            $startDate = Carbon::now()->startOfMonth();
            $endDate   = Carbon::now()->endOfMonth();
            $result    = [];
            // Boucle sur chaque jour du mois
            for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
                $formattedDate = $date->toDateString();
                // Compte les déclarations de perte et les publications pour la date donnée
                $result[] = [
                    'date'         => $formattedDate,
                    'declarations' => DeclarationDePerte::whereDate(
                        'created_at', $formattedDate
                    )->count(),
                    'publications' => Document::whereDate(
                        'created_at', $formattedDate
                    )->count(),
                ];
            }
            // Récupérer les statistiques de publications et déclarations de perte par mois
            return $result;
        });
        // Retourner les données en JSON
        return response()->json(['data' => $data]);
    }

    // Fonction pour obtenir les statistiques de publications et déclarations de perte par type de document
    public function getDocumentStatusCountWithTrashed(Request $request)
    {
        // Verifier si le user est authentifié
        if (! Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié',
            ], 401);
        }

        $data = Cache::remember('document_status_count', 900, function () {
            return [
                'récupéré'     => Document::withTrashed()->where('statut', 'récupéré')->count(),
                'non_récupéré' => Document::withTrashed()->where('statut', 'non récupéré')->count(),
            ];
        });

        return response()->json($data);
    }

    /**
     * Fonction pour obtenir les coordonnées d'une ville
     */

    /**
     * Récupère les coordonnées géographiques (latitude, longitude) d'une localité.
     *
     * @param string $location Nom de la localité (ex. "Dakar")
     * @return array Tableau contenant latitude, longitude et un message d'erreur éventuel
     */
    // ══════════════════════════════════════════════════════════════
    // GÉOLOCALISATION — avec cache 48h
    // ══════════════════════════════════════════════════════════════
    /**
     * Coordonnées d'un lieu inconnu du référentiel (OpenStreetMap / Nominatim).
     * Un échec n'est gardé en cache qu'une heure : il ne bloque plus la carte pendant 30 jours.
     */
    private function geocode(string $location): ?array
    {
        if (app()->runningUnitTests()) {
            return null;
        }

        $key = 'geo_sn_' . md5(Str::lower(trim($location)));
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $coords = [];
        try {
            $response = Http::timeout(4)
                ->withHeaders(['User-Agent' => 'DocTrack/1.0 (sendoctrack@gmail.com)'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $location . ', Sénégal', 'format' => 'json', 'limit' => 1, 'countrycodes' => 'sn',
                ]);
            if ($response->successful() && ! empty($response->json()[0])) {
                $coords = ['lat' => (float) $response->json()[0]['lat'], 'lon' => (float) $response->json()[0]['lon']];
            }
        } catch (\Throwable $e) {
            Log::warning("Géocodage impossible pour '$location' : " . $e->getMessage());
        }

        Cache::put($key, $coords, $coords ? now()->addDays(30) : now()->addHour());

        return $coords ?: null;
    }

    /**
     * Carte : nombre de publications par département.
     * Le département vient, dans l'ordre : du point GPS où le document a été trouvé, du lieu saisi
     * (référentiel des départements et quartiers courants), de la structure qui le garde,
     * puis en dernier recours du géocodage OpenStreetMap.
     */
    public function getPublicationsByLocation()
    {
        $data = Cache::remember('publications_by_department', now()->addMinutes(10), function () {
            $counts = [];
            $unlocated = 0;

            Document::query()
                ->where(fn ($q) => $q->whereNull('found_channel')->orWhere('found_channel', '!=', Document::CHANNEL_QR_SCAN))
                ->with('custodyOrganization:id,latitude,longitude,name')
                ->select(['id', 'Location', 'found_latitude', 'found_longitude', 'custody_organization_id'])
                ->chunkById(500, function ($documents) use (&$counts, &$unlocated) {
                    foreach ($documents as $document) {
                        $department = \App\Support\SenegalGazetteer::nearest(
                            $document->found_latitude !== null ? (float) $document->found_latitude : null,
                            $document->found_longitude !== null ? (float) $document->found_longitude : null,
                        )
                            ?? \App\Support\SenegalGazetteer::locate($document->Location)
                            ?? \App\Support\SenegalGazetteer::locate($document->custodyOrganization?->name)
                            ?? \App\Support\SenegalGazetteer::nearest($document->custodyOrganization?->latitude, $document->custodyOrganization?->longitude);

                        if (! $department && $document->Location && ($coords = $this->geocode($document->Location))) {
                            $department = \App\Support\SenegalGazetteer::nearest($coords['lat'], $coords['lon']);
                        }

                        $department ? $counts[$department] = ($counts[$department] ?? 0) + 1 : $unlocated++;
                    }
                });

            arsort($counts);

            return [
                'locations' => collect($counts)->map(function ($count, $name) {
                    [$region, $lat, $lon] = \App\Support\SenegalGazetteer::DEPARTMENTS[$name];

                    return ['name' => $name, 'region' => $region, 'latitude' => $lat, 'longitude' => $lon, 'publications' => $count];
                })->values()->all(),
                'unlocated' => $unlocated,
            ];
        });

        // Liste (format historique) ; les publications non localisées sont indiquées en en-tête.
        return response()->json($data['locations'])
            ->header('X-Unlocated', (string) $data['unlocated'])
            ->header('Access-Control-Expose-Headers', 'X-Unlocated');
    }


    /** Les documents déposés au guichet se gèrent dans l'espace institutionnel (API v2). */
    private static function counterDocumentLocked()
    {
        return response()->json([
            'success' => false,
            'message' => 'Ce document est sous la garde d\'une structure : il se gère depuis l\'espace institutionnel.',
        ], 409);
    }

    /**
     * Suivi de la collecte par le trouveur : quelle structure va l'appeler, quand.
     */
    public function collection($uuid)
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        abort_unless(Auth::id() === $document->user_id || Auth::user()->isSuperAdmin(), 403);

        $collection = $document->collectionRequests()->with('organization')->latest('id')->first();

        return response()->json(['collection' => $collection ? self::collectionSummary($collection) : null]);
    }

    /** Ce que le trouveur voit de la collecte (jamais le nom des agents). */
    public static function collectionSummary(\App\Models\CollectionRequest $c): array
    {
        $org = $c->organization;

        return [
            'uuid'          => $c->uuid,
            'status'        => $c->status,
            'structure'     => $org ? $org->only(['name', 'address', 'phone']) : null,
            'distance_km'   => $c->distance_km,
            'method'        => $c->method,
            'scheduled_at'  => $c->scheduled_at,
            'meeting_place' => $c->meeting_place,
            'collected_at'  => $c->collected_at,
        ];
    }
}

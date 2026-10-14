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

        if ($user && $user->isSuperAdmin()) {
            $documents = Document::withTrashed()
                ->with(['user', 'documentType', 'media', 'custodyOrganization'])
                ->latest()
                ->paginate($perPage);
        } else {
            $documents = Document::whereNull('deleted_at')
                // Les signalements par QR ne sont pas publiés : le propriétaire est prévenu directement.
                ->where(fn ($q) => $q->whereNull('found_channel')->orWhere('found_channel', '!=', Document::CHANNEL_QR_SCAN))
                ->with(['user', 'documentType', 'media', 'custodyOrganization'])
                ->latest()
                ->paginate($perPage);
        }

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

        $documents = Document::withTrashed()
            ->with(['user', 'documentType', 'media', 'custodyOrganization'])
            ->latest()
            ->paginate(20);

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // STORE — Créer une publication
    // ════════════════════════════════════════════════════════

    public function store(StoreDocumentRequest $request, EmailNotificationService $emailService, SmsService $smsService)
    {
        $validatedData = $request->validated();

        // Pièce scannée juste avant : la photo vient du scan (déjà lue et masquée).
        $scan = ! empty($validatedData['ocr_scan_id'])
            ? \App\Models\OcrScan::where('uuid', $validatedData['ocr_scan_id'])->where('user_id', Auth::id())->first()
            : null;

        // ── Vérifie l'image AVANT de créer le document ──
        if (! $scan && (! $request->hasFile('image') || ! $request->file('image')->isValid())) {
            return response()->json(['error' => 'Aucun fichier image valide fourni'], 400);
        }

        // ── Crée le document SANS image ──
        $document = Document::create([
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
        Cache::forget('publications_by_location');

        return response()->json([
            'success'  => true,
            'message'  => $collection?->organization
                ? 'Document publié. ' . $collection->organization->name . ' va vous contacter pour le récupérer.'
                : 'Document créé avec succès.',
            'document'   => new DocumentResource($document->fresh(['user', 'documentType', 'custodyOrganization'])),
            'collection' => $collection ? self::collectionSummary($collection) : null,
        ], 201);
    }

    /**
     * Gérer la demande de restitution.
     */
    public function requestRestitution($uuid, \App\Services\Restitution\DirectRestitutionService $restitutions)
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

        // Le trouveur ne l'a plus : il est dans une structure, restitution au guichet.
        if ($document->found_channel === Document::CHANNEL_COUNTER || $document->isInCustody()) {
            return response()->json([
                'success'   => false,
                'message'   => 'Ce document est conservé par ' . ($document->custodyOrganization?->name ?? 'une structure officielle')
                    . '. Présentez-vous à ce guichet avec une pièce justificative.',
                'custody'   => $document->custodyOrganization?->only(['uuid', 'name', 'address', 'phone']),
                'reference' => $document->reference,
            ], 409);
        }

        if ($document->statut === 'récupéré') {
            return response()->json(['success' => false, 'message' => 'Ce document a déjà été restitué.'], 409);
        }

        // Règle : seul le propriétaire CONFIRMÉ par DocTrack peut demander une remise directe.
        $match = $restitutions->confirmedMatchFor($document, $fromUser);
        if (! $match) {
            return response()->json([
                'success' => false,
                'code'    => 'no_confirmed_match',
                'message' => 'La remise directe est réservée au propriétaire confirmé par DocTrack. '
                    . 'Faites d\'abord une déclaration de perte : si ce document est le vôtre, vous serez prévenu dès que la correspondance sera confirmée. '
                    . 'Vous pouvez aussi vous présenter à la structure de police ou de gendarmerie la plus proche.',
            ], 403);
        }

        if ($active = $restitutions->activeRequest($document)) {
            return response()->json([
                'success' => false,
                'message' => $active->requester_user_id === $fromUser->id
                    ? 'Vous avez déjà une demande en cours pour ce document.'
                    : 'Une demande de restitution est déjà en cours pour ce document.',
                'request' => ['uuid' => $active->uuid, 'status' => $active->status, 'expires_at' => $active->expires_at],
            ], 400);
        }

        $request = $restitutions->request($document, $fromUser, $match);

        return response()->json([
            'success' => true,
            'message' => 'Demande envoyée. Le trouveur est prévenu par SMS et par email ; dès qu\'il accepte, vous recevrez son numéro.',
            'request' => [
                'uuid'       => $request->uuid,
                'status'     => $request->status,
                'expires_at' => $request->expires_at,
            ],
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
            Cache::forget('publications_by_location');

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
            ->with(['user', 'media']) // Charge les informations de l'utilisateur associé à chaque document
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $documents,
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
    private function getCoordinates(string $location): array
    {
        $cacheKey = 'coords_' . Str::slug($location);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($location) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['User-Agent' => 'DocTrack/1.0 (sendoctrack@gmail.com)'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q'      => $location . ', Sénégal',
                        'format' => 'json',
                        'limit'  => 1,
                    ]);

                if ($response->successful() && ! empty($response->json())) {
                    $data = $response->json()[0];
                    return [
                        'latitude'  => (float) $data['lat'],
                        'longitude' => (float) $data['lon'],
                    ];
                }
            } catch (\Exception $e) {
                Log::error("Geocoding error for '$location': " . $e->getMessage());
            }

            return ['latitude' => null, 'longitude' => null];
        });
    }
    // Méthode pour récupérer les publications et leurs coordonnées
    public function getPublicationsByLocation()
    {
        $data = Cache::remember('publications_by_location', now()->addHours(2), function () {
            $publications = Document::select('Location', DB::raw('COUNT(*) as publications'))
                ->groupBy('Location')
                ->get();

            return $publications->map(function ($item) {
                $coords = $this->getCoordinates($item->Location);
                return [
                    'name'         => $item->Location,
                    'latitude'     => $coords['latitude'],
                    'longitude'    => $coords['longitude'],
                    'publications' => $item->publications,
                ];
            })->values();
        });

        return response()->json($data);
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

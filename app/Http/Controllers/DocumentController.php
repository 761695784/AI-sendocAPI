<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
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

        if ($user && $user->hasRole('Admin')) {
            $documents = Document::withTrashed()
                ->with(['user', 'documentType', 'media'])
                ->latest()
                ->paginate(20);
        } else {
            $documents = Document::whereNull('deleted_at')
                ->with(['user', 'documentType', 'media'])
                ->latest()
                ->paginate(20);
        }

        return DocumentResource::collection($documents);
    }

    // ══════════════════════════════════════════════════════════════
    // Afficher toutes les publications y compris celles supprimer en soft delete — Admin seulement
    // ══════════════════════════════════════════════════════════════
    public function getAllPublications(Request $request)
    {
        if (! Auth::user()?->hasRole('Admin')) {
            return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
        }

        $documents = Document::withTrashed()
            ->with(['user', 'documentType', 'media'])
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

        // ── Vérifie l'image AVANT de créer le document ──
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) {
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
        ]);

        // ── Upload + conversions via MediaLibrary ── génère automatiquement : thumb, blurred, optimized en WebP
        $document->addMediaFromRequest('image')
            ->toMediaCollection('document_image');

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

        // Invalide le cache des stats après ajout
        Cache::forget('statistics_month_' . now()->format('Y_m'));
        Cache::forget('publications_by_type');
        Cache::forget('publications_by_location');

        return response()->json([
            'success'  => true,
            'message'  => 'Document créé avec succès.',
            'document' => new DocumentResource($document->load(['user', 'documentType'])),
        ], 201);
    }

    /**
     * Gérer la demande de restitution.
     */
    public function requestRestitution($uuid)
    {
        // Récupérer le document concerné par son uuid
        $document = Document::where('uuid', $uuid)->firstOrFail();

        // L'utilisateur connecté qui clique sur "Restituer"
        $fromUser = Auth::user();

        // Vérifier si l'utilisateur connecté est le propriétaire du document
        if ($fromUser->id === $document->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas demander la restitution de votre propre document.',
            ], 403); // Code 403 Forbidden
        }

        if ($document->statut === 'récupéré') {
            return response()->json([
                'success' => false,
                'message' => 'Ce document a déjà été restitué.',
            ], 409);
        }

        // Vérifier si une demande de restitution pour ce document a déjà été faite par cet utilisateur
        $existingEmailLog = EmailLog::where('requester_user_id', $fromUser->id)
            ->where('document_id', $document->uuid)
            ->where('subject', 'Demande de restitution') // ← sujet exact
            ->first();

        if ($existingEmailLog) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà demandé la restitution de ce document.',
            ], 400); // Code 400 Bad Request
        }

        // L'utilisateur qui a publié le document
        $toUser = $document->user;
                                                                                      // Envoyer la notification par SMS
        $phoneNumber = $toUser->Phone;                                                // Assure-toi que le champ du numéro de téléphone est correct
        $documentUrl = self::frontendDocumentUrl($document);
        $message     = 'Bonjour, vous avez une demande de restitution pour le document : ' . $document->OwnerFirstName . ' ' . $document->OwnerLastName . '. Consultez-le ici : ' . $documentUrl;
        // Envoyer la notification par email
        $toUser->notify(new RestitutionRequestNotification($fromUser, $document));

        // Appel à la méthode pour l'envoi de SMS
        // $this->sendSMS($phoneNumber, $message);
        //  $smsService->sendSMS($toUser->Phone, $message);

        // ── Log d'activité (AVANT le return) ──
        activity()
            ->causedBy($fromUser)
            ->performedOn($document)
            ->withProperties([
                'document_uuid' => $document->uuid,
                'from_user_id'  => $fromUser->id,
                'to_user_id'    => $toUser->id,
            ])
            ->log('Demande de restitution envoyée');

        // Retourner une réponse JSON
        return response()->json(['message' => 'Demande de restitution envoyée avec succès.']);
    }

    /**
     * Afficher le document specifique.
     */
    public function show($uuid)
    {
        $document = Document::with(['user', 'documentType', 'media'])
            ->where('uuid', $uuid)
            ->firstOrFail();

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

        $validatedData = $request->validated();

        // L'image n'est pas une colonne : elle passe par MediaLibrary.
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $document->addMediaFromRequest('image')->toMediaCollection('document_image');
        }
        unset($validatedData['image']);

        $document->update($validatedData);

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

        if ($user->hasRole('Admin') || $document->user_id === $user->id) {

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
            ->with(['user', 'documentType', 'media'])
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
        if (! $user || ! $user->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer uniquement les documents supprimés (soft deleted)
        $documents = Document::onlyTrashed()->with(['user', 'documentType', 'media'])
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
        if (! $user || ! $user->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer les documents dont le statut est "récupéré", y compris ceux qui sont soft-deleted
        $documents = Document::withTrashed()
            ->where('statut', 'récupéré')
            ->with(['user', 'documentType', 'media'])
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
        if (! $user || ! $user->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Récupérer les documents dont le statut est "non récupéré", y compris ceux qui sont soft-deleted
        $documents = Document::withTrashed()
            ->where('statut', 'non récupéré')
            ->with(['user', 'documentType', 'media'])
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

}

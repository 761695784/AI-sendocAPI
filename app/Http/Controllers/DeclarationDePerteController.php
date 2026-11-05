<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreDeclarationDePerteRequest;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\RestitutionRequest;
use App\Services\Declarations\OnlineDeclarationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DeclarationDePerteController extends Controller
{

    // ══════════════════════════════════════════════════════════════
    // STORE — Créer une déclaration
    // ══════════════════════════════════════════════════════════════
    public function store(StoreDeclarationDePerteRequest $request, OnlineDeclarationService $declarations)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        [$declaration, $certificat] = $declarations->create($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Déclaration de perte créée avec succès.',
            'data'    => [
                'declaration' => $declaration,
                'certificat'  => $certificat,
                'pdf_url'     => url('/api/certificats/' . $certificat->uuid . '/voir'), // Lien pour voir le PDF via l'API
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════
    // INDEX — Liste des déclarations
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $user = Auth::user();

        // Si l'utilisateur n'est pas authentifié, retourner une erreur
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401); // Code 401 Unauthorized
        }

        // Si l'utilisateur est un administrateur, récupérer toutes les déclarations (y compris celles supprimées)
        if ($user->isSuperAdmin()) {
            // Par défaut les déclarations en cours ; ?trashed=only (corbeille) ou ?trashed=with (tout).
            $declarations = \App\Http\Controllers\DocumentController::applyTrashedFilter(DeclarationDePerte::query(), $request->query('trashed'))
                ->with(['user', 'documentType'])
                ->latest()
                ->paginate(max(1, min(100, (int) $request->query('per_page', 20))));
        } else {
            // Si l'utilisateur est un simple utilisateur, ne récupérer que ses propres déclarations (non supprimées)
            $declarations = DeclarationDePerte::with(['user', 'documentType'])
                ->where('user_id', $user->id)
                ->latest()
                ->paginate(20);
        }

        return response()->json([
            'success' => true,
            'data'    => $declarations->items(), // ← plus de double imbrication data.data
            'meta'    => [
                'current_page' => $declarations->currentPage(),
                'last_page'    => $declarations->lastPage(),
                'total'        => $declarations->total(),
            ],
        ]);
    }

    // Méthode pour afficher toutes les déclarations supprimées du user connecté
    public function trashedDeclarations()
    {
        // verifier si le user est connecte
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401); // Code 401 Unauthorized
        }

        // Récupérer les déclarations supprimées de l'utilisateur connecté
        $declarations = DeclarationDePerte::onlyTrashed()
            ->where('user_id', $user->id)
            ->with('user')
            ->get();

        if ($declarations->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Aucune déclaration supprimée trouvée.',
                'data'    => [],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $declarations,
        ]);
    }

    // Méthode pour restaurer une déclaration supprimée
    public function restoreTrashedDeclaration($uuid)
    {
        // Vérifier si l'utilisateur est connecté
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        // Vérifier si la déclaration existe et si c'est une déclaration supprimée de l'utilisateur connecté
        $declaration = DeclarationDePerte::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        if ($declaration->user_id === $user->id) {
            $declaration->restore();

            activity()
                ->causedBy($user)
                ->performedOn($declaration)
                ->withProperties(['uuid' => $declaration->uuid])
                ->log('Déclaration restaurée');

            return response()->json([
                'success' => true,
                'message' => 'Déclaration restaurée avec succès.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez restaurer que vos propres déclarations.',
        ], 403);
    }

    // Fonction pour obtenir les déclarations faites par l'utilisateur connecté
    public function getUserDeclarations()
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour voir vos déclarations.',
            ], 401);
        }

        // Récupérer les déclarations faites par l'utilisateur connecté avec les informations de l'utilisateur et du type de document
        $declarations = DeclarationDePerte::where('user_id', $user->id)
            ->with([
                'user', 'documentType', 'certificat',
                // Récépissé (en ligne) et certificat de perte délivré par une structure.
                'receipt', 'officialCertificate.issuingOrganization',
                'matches' => fn ($q) => $q->where('status', DocumentMatch::STATUS_CONFIRMED)
                    ->with(['document.documentType', 'document.custodyOrganization', 'document.restitutionRequests']),
            ])
            ->latest('id')
            ->get()
            ->each(function (DeclarationDePerte $declaration) use ($user) {
                // Documents retrouvés (correspondances confirmées) et ce que le propriétaire peut faire.
                $declaration->setAttribute('found_documents', $declaration->matches
                    ->filter(fn ($m) => $m->document)
                    ->map(fn ($m) => self::foundDocumentForOwner($m->document, $user->id))
                    ->values());
                $declaration->unsetRelation('matches');
            });

        return response()->json([
            'success' => true,
            'data'    => $declarations,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SHOW — Déclaration spécifique
    // ══════════════════════════════════════════════════════════════
    public function show($uuid)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        // Charger les informations de l'utilisateur et le type de document
        $declaration = DeclarationDePerte::with(['user', 'documentType'])->where('uuid', $uuid)->firstOrFail();

        // Si l'utilisateur est un simple utilisateur, vérifier qu'il est propriétaire de la déclaration ou bien est ce l'administrator
        if ($user->isSuperAdmin() || $declaration->user_id === $user->id) {
            return response()->json([
                'success' => true,
                'data'    => $declaration,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez voir que vos propres déclarations.',
        ], 403);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Supprimer une déclaration
    // ══════════════════════════════════════════════════════════════
    public function destroy($uuid)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }
        // Récupérer la declaration par son uuid
        $declaration = DeclarationDePerte::where('uuid', $uuid)->firstOrFail();

        // Une déclaration enregistrée ou certifiée au guichet d'une structure ne se supprime pas
        // depuis l'espace citoyen (elle porte un certificat officiel).
        if ($declaration->isInstitutional() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => $declaration->isCertified()
                    ? 'Cette déclaration a été certifiée par une structure : elle ne peut plus être supprimée depuis votre espace.'
                    : 'Cette déclaration a été enregistrée par une structure : seule celle-ci peut l\'annuler.',
            ], 403);
        }

        // Vérifier si l'utilisateur est le propriétaire de la déclaration ou si c'est un administrateur
        if ($user->isSuperAdmin() || $declaration->user_id === $user->id) {

            // ── Log d'activité ──
            /** @var \App\Models\User $user */
            activity()
                ->causedBy($user)
                ->performedOn($declaration)
                ->withProperties([
                    'uuid'       => $declaration->uuid,
                    'deleted_by' => $user->id,
                ])
                ->log('Déclaration supprimée');

            $declaration->delete();

            Cache::forget('statistics_month_' . now()->format('Y_m'));

            return response()->json([
                'success' => true,
                'message' => 'Déclaration de perte supprimée avec succès.',
            ]);
        }

        // retourner la reponse  de l'api
        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez supprimer que vos propres déclarations.',
        ], 403);
    }

    /**
     * Document retrouvé, vu par le propriétaire (aucune donnée du trouveur).
     * next_step : « counter » (se présenter à la structure qui le garde),
     *             « direct » (demander la remise directe au trouveur),
     *             « done » (déjà restitué), « wait » (en cours de récupération par une structure).
     */
    public static function foundDocumentForOwner(Document $doc, int $ownerId): array
    {
        $custody = $doc->custodyOrganization;
        $request = $doc->restitutionRequests
            ->where('requester_user_id', $ownerId)
            ->sortByDesc('id')
            ->first();

        $nextStep = match (true) {
            $doc->lifecycle_status === Document::LIFECYCLE_RESTITUTED => 'done',
            $custody !== null                                      => 'counter',
            $doc->found_channel === Document::CHANNEL_CITIZEN        => 'direct',
            default                                                => 'wait',
        };

        return [
            'uuid'             => $doc->uuid,
            'reference'        => $doc->reference,
            'document_type'    => $doc->documentType?->TypeName,
            'lifecycle_status' => $doc->lifecycle_status,
            'found_channel'    => $doc->found_channel,
            'custody'          => $custody ? $custody->only(['uuid', 'name', 'address', 'phone']) : null,
            'next_step'        => $nextStep,
            'restitution_request' => $request ? [
                'uuid'       => $request->uuid,
                'status'     => $request->status,
                'expires_at' => $request->expires_at,
                'active'     => in_array($request->status, RestitutionRequest::ACTIVE_STATUSES, true),
            ] : null,
        ];
    }
}

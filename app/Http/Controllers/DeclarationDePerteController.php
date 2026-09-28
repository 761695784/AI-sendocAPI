<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreDeclarationDePerteRequest;
use App\Jobs\NotifyDeclarantJob;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Services\CertificatDePerteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DeclarationDePerteController extends Controller
{

    // ══════════════════════════════════════════════════════════════
    // STORE — Créer une déclaration
    // ══════════════════════════════════════════════════════════════
    public function store(StoreDeclarationDePerteRequest $request, CertificatDePerteService $certificatService, \App\Services\Declarations\CitizenProfileService $citizens)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }
        // Effectuer les validations
        $validatedData = $request->validated();
        $declaration   = DeclarationDePerte::create([
            'Title'             => $validatedData['Title'],
            'FirstNameInDoc'    => $validatedData['FirstNameInDoc'],
            'LastNameInDoc'     => $validatedData['LastNameInDoc'],
            'DocIdentification' => $validatedData['DocIdentification'] ?? null,
            'document_type_id'  => $validatedData['document_type_id'],
            'user_id'           => $user->id,
            'channel'           => DeclarationDePerte::CHANNEL_ONLINE,
            'citizen_profile_id'=> $citizens->forAccount($user)->id,
        ]);

        // ── Log d'activité ──
        /** @var \App\Models\User $user */
        $user = Auth::user();
        activity()
            ->causedBy($user)
            ->performedOn($declaration)
            ->withProperties([
                'Title'            => $declaration->Title,
                'FirstNameInDoc'   => $declaration->FirstNameInDoc,
                'LastNameInDoc'    => $declaration->LastNameInDoc,
                'document_type_id' => $declaration->document_type_id,
            ])
            ->log('Déclaration de perte créée');

        // Générer automatiquement le certificat
        $certificat = $certificatService->genererCertificat($declaration);

        // Invalide le cache des stats
        Cache::forget('statistics_month_' . now()->format('Y_m'));
        // Recherche des documents trouvés correspondants (non restitués,
        // non supprimés, publiés par quelqu'un d'autre). Les notifications
        // partent via le job (file d'attente, ou immédiatement en mode sync).
        $matchingDocuments = Document::where('document_type_id', $validatedData['document_type_id'])
            ->whereRaw('LOWER(OwnerFirstName) = LOWER(?)', [$validatedData['FirstNameInDoc']])
            ->whereRaw('LOWER(OwnerLastName) = LOWER(?)', [$validatedData['LastNameInDoc']])
            ->where('statut', 'non récupéré')
            ->where('user_id', '!=', $user->id)
            ->get();

        foreach ($matchingDocuments as $document) {
            dispatch(new NotifyDeclarantJob(
                $document,
                $user,
                DocumentController::frontendDocumentUrl($document),
                $declaration->id
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Déclaration de perte créée avec succès.',
            'data'    => [
                'declaration' => $declaration,
                'certificat'  => $certificat,
                                                                                         // 'pdf_url' => asset('storage/' . $certificat->pdf_path)
                'pdf_url'     => url('/api/certificats/' . $certificat->uuid . '/voir'), // Lien pour voir le PDF via l'API
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════
    // INDEX — Liste des déclarations
    // ══════════════════════════════════════════════════════════════
    public function index()
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
            $declarations = DeclarationDePerte::withTrashed()
                ->with(['user', 'documentType'])
                ->latest()
                ->paginate(20);
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
            ->with(['user', 'documentType', 'certificat']) // Charger les relations user et documentType
            ->get();
        // dd($declarations->first()->certificat);

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

        // Une déclaration enregistrée au guichet d'une structure ne se supprime pas
        // depuis l'espace citoyen (elle porte un certificat officiel).
        if ($declaration->isCounter() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette déclaration a été enregistrée par une structure : seule celle-ci peut l\'annuler.',
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
}

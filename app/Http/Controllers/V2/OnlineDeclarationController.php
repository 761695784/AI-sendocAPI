<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Http\Requests\V2\CertifyOnlineDeclarationRequest;
use App\Http\Resources\V2\DeclarationResource;
use App\Models\DeclarationDePerte;
use App\Services\Declarations\OnlineDeclarationCertificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Guichet : certifier une déclaration faite en ligne par le citoyen.
 * Toute structure habilitée peut certifier, quel que soit le lieu de la perte.
 */
class OnlineDeclarationController extends Controller
{
    public function __construct(private OnlineDeclarationCertificationService $certification) {}

    /**
     * Recherche par référence (DT-…) ou par code / lien du QR code du récépissé.
     * Résumé minimal : aucune donnée nominative complète avant la vérification d'identité.
     */
    public function lookup(Request $request)
    {
        $structure = $this->structure();
        $data = $request->validate(['q' => 'required|string|min:4|max:255']);

        $declaration = $this->certification->find($data['q']);

        activity()
            ->causedBy($request->user())
            ->withProperties(['query' => mb_substr($data['q'], 0, 60), 'found' => (bool) $declaration, 'organization' => $structure->uuid])
            ->log('Recherche d\'une déclaration en ligne au guichet');

        if (! $declaration) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune déclaration en ligne ne correspond à cette référence ou à ce code.',
            ], 404);
        }

        return response()->json($this->certification->lookupSummary($declaration));
    }

    /** Vérifie l'identité, rattache la déclaration à la structure et délivre le certificat de perte. */
    public function certify(CertifyOnlineDeclarationRequest $request, DeclarationDePerte $declaration)
    {
        $structure = $this->structure();

        if ($declaration->channel !== DeclarationDePerte::CHANNEL_ONLINE) {
            return response()->json([
                'success' => false,
                'message' => 'Seule une déclaration faite en ligne peut être certifiée ici.',
            ], 422);
        }
        if ($declaration->isCertified()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette déclaration a déjà été certifiée.',
            ], 409);
        }

        $declaration = $this->certification->certify($declaration, $request->user(), $structure, $request->validated());

        return response()->json((new DeclarationResource($declaration))->resolve($request), 201);
    }

    private function structure()
    {
        $structure = ResolveActiveOrganization::current();
        abort_unless($structure, 403, 'Aucune structure active : vous n\'êtes rattaché à aucune structure.');
        $structure->loadMissing('type');
        Gate::authorize('createAtCounter', [DeclarationDePerte::class, $structure]);

        return $structure;
    }
}

<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\StoreCitizenProfileRequest;
use App\Http\Resources\V2\CitizenProfileResource;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Services\Declarations\CitizenProfileService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Recherche / création de la victime au guichet.
 *
 * Recherche volontairement étroite (anti-fouille), sur toute la plateforme :
 *  - par téléphone exact, ou
 *  - par nom + prénom exacts (date de naissance facultative pour affiner).
 * Les citoyens déjà inscrits (comptes) sont trouvés même sans déclaration antérieure.
 */
class CitizenProfileController extends Controller
{
    public function __construct(private CitizenProfileService $service) {}

    public function search(Request $request)
    {
        Gate::authorize('searchCitizens', DeclarationDePerte::class);

        $request->validate([
            'phone'      => 'required_without_all:last_name|nullable|string|max:20',
            'last_name'  => 'required_without:phone|nullable|string|max:100',
            'first_name' => 'required_with:last_name|nullable|string|max:100',
            'birth_date' => 'nullable|date',
        ]);

        $results = new EloquentCollection($this->service->search($request->only(['phone', 'last_name', 'first_name', 'birth_date']))->all());
        $results->loadMissing('user');

        activity()
            ->causedBy($request->user())
            ->withProperties(['criteria' => $request->filled('phone') ? 'phone' : 'identity', 'results' => $results->count()])
            ->log('Recherche de citoyen au guichet');

        return response()->json(CitizenProfileResource::collection($results)->resolve());
    }

    public function store(StoreCitizenProfileRequest $request)
    {
        Gate::authorize('createCitizen', DeclarationDePerte::class);
        $data = $request->validated();

        // Un numéro de téléphone = une seule personne : on propose la fiche existante au lieu d'un doublon.
        if ($this->service->phoneBelongsToStaff($data['phone'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => 'Ce numéro est celui d\'un compte agent ou administrateur : saisissez le numéro personnel de la victime.',
            ]);
        }
        if ($existing = $this->service->findByPhone($data['phone'])) {
            $sameName = Str::lower(Str::ascii(trim($existing->last_name))) === Str::lower(Str::ascii(trim($data['last_name'])));

            return response()->json([
                'success'  => false,
                'message'  => $sameName
                    ? 'Cette personne est déjà enregistrée avec ce numéro de téléphone : utilisez sa fiche.'
                    : 'Ce numéro de téléphone est déjà enregistré pour une autre personne. Vérifiez le numéro, ou utilisez la fiche existante.',
                'existing' => (new CitizenProfileResource($existing->loadMissing('user')))->resolve(),
            ], 409);
        }
        if (! empty($data['email']) && $this->service->emailTaken($data['email'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'Cette adresse email est déjà utilisée par un autre compte ou une autre personne.',
            ]);
        }

        $profile = $this->service->createAtCounter($data, $request->user(), ResolveActiveOrganization::current());

        return response()->json((new CitizenProfileResource($profile))->resolve(), 201);
    }

    /**
     * Créer le compte DocTrack d'une personne déjà enregistrée (avec son email),
     * ou renvoyer le lien d'activation si son compte n'est pas encore activé.
     */
    public function account(Request $request, CitizenProfile $citizen, \App\Services\Citizens\CitizenAccountService $accounts)
    {
        Gate::authorize('createCitizen', DeclarationDePerte::class);
        $structure = ResolveActiveOrganization::current();

        if ($citizen->user_id) {
            $user = $citizen->user;
            if ($user->email_verified_at) {
                return response()->json(['success' => false, 'message' => 'Cette personne a déjà un compte actif.'], 409);
            }
            $accounts->sendActivation($user, $structure);
        } else {
            $data = $request->validate(['email' => 'required|email|max:100']);
            $accounts->createForProfile($citizen, $data['email'], $request->user(), $structure);
        }

        return response()->json((new CitizenProfileResource($citizen->fresh()))->resolve());
    }
}

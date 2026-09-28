<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\StoreCitizenProfileRequest;
use App\Http\Resources\V2\CitizenProfileResource;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Services\Declarations\CitizenProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Recherche / création de la victime au guichet.
 *
 * Recherche volontairement étroite (anti-fouille) :
 *  - par téléphone exact, ou
 *  - par nom + prénom + date de naissance exacts.
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
            'birth_date' => 'required_with:last_name|nullable|date',
        ]);

        if ($request->filled('phone')) {
            $results = CitizenProfile::wherePhone($request->input('phone'))->limit(10)->get();
        } else {
            $results = CitizenProfile::whereDate('birth_date', $request->date('birth_date'))
                ->whereRaw('LOWER(last_name) = ?', [Str::lower($request->input('last_name'))])
                ->whereRaw('LOWER(first_name) = ?', [Str::lower($request->input('first_name'))])
                ->limit(10)
                ->get();
        }

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

        if ($existing = $this->service->findDuplicate($data['phone'], $data['last_name'])) {
            return response()->json([
                'success'  => false,
                'message'  => 'Une personne avec ce téléphone et ce nom existe déjà.',
                'existing' => (new CitizenProfileResource($existing))->resolve(),
            ], 409);
        }

        $profile = $this->service->createAtCounter($data, $request->user(), ResolveActiveOrganization::current());

        return response()->json((new CitizenProfileResource($profile))->resolve(), 201);
    }
}

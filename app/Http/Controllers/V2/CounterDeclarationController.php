<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Http\Requests\V2\StoreCounterDeclarationRequest;
use App\Http\Resources\V2\DeclarationResource;
use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Services\Access\OrganizationAccess;
use App\Services\Declarations\CitizenProfileService;
use App\Services\Declarations\CounterDeclarationService;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Déclarations de perte enregistrées au guichet (espace agent).
 */
class CounterDeclarationController extends Controller
{
    public function __construct(
        private CounterDeclarationService $declarations,
        private CitizenProfileService $citizens,
        private OrganizationAccess $access,
    ) {}

    /**
     * Déclarations du périmètre de l'utilisateur.
     * Filtres : status, reference, from, to, organization (uuid).
     */
    public function index(Request $request)
    {
        Gate::authorize('viewInstitutional', DeclarationDePerte::class);
        $user = $request->user();

        $query = DeclarationDePerte::query()
            ->with(['items.documentType', 'citizenProfile', 'organization', 'recordedBy', 'certificat'])
            ->where('channel', DeclarationDePerte::CHANNEL_COUNTER);

        if (! $user->isSuperAdmin()) {
            $allowed = $this->access->visibleOrganizations($user)->get()
                ->filter(fn ($org) => $this->access->can($user, 'declarations.view', $org))
                ->pluck('id');
            $query->whereIn('organization_id', $allowed);
        }

        $query->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('reference'), fn ($q, $r) => $q->where('reference', $r))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->query('organization'), fn ($q, $o) => $q->whereHas('organization', fn ($oq) => $oq->where('uuid', $o)));

        $page = $query->latest()->paginate(25);
        $page->getCollection()->transform(fn ($d) => (new DeclarationResource($d))->summary()->resolve($request));

        return response()->json($page);
    }

    public function store(StoreCounterDeclarationRequest $request)
    {
        $structure = ResolveActiveOrganization::current();
        abort_unless($structure, 403, 'Aucune structure active : vous n\'êtes rattaché à aucune structure.');
        $structure->loadMissing('type');
        Gate::authorize('createAtCounter', [DeclarationDePerte::class, $structure]);

        $data  = $request->validated();
        $agent = $request->user();

        if (! empty($data['citizen_profile_uuid'])) {
            $citizen = CitizenProfile::where('uuid', $data['citizen_profile_uuid'])->firstOrFail();
        } else {
            Gate::authorize('createCitizen', DeclarationDePerte::class);
            $citizen = $this->citizens->findDuplicate($data['citizen']['phone'], $data['citizen']['last_name'])
                ?? $this->citizens->createAtCounter($data['citizen'], $agent, $structure);

            // Personne déjà connue mais sans compte : l'email saisi crée son compte.
            if (! $citizen->user_id && ! empty($data['citizen']['email'])) {
                app(\App\Services\Citizens\CitizenAccountService::class)->createForProfile($citizen, $data['citizen']['email'], $agent, $structure);
                $citizen->refresh();
            }
        }

        $declaration = $this->declarations->register($agent, $structure, $citizen, $data);

        return response()->json((new DeclarationResource($declaration))->resolve($request), 201);
    }

    public function show(Request $request, DeclarationDePerte $declaration)
    {
        Gate::authorize('view', $declaration);

        // Consultation d'un dossier nominatif : tracée.
        activity()
            ->causedBy($request->user())
            ->performedOn($declaration)
            ->withProperties(['reference' => $declaration->reference])
            ->log('Consultation d\'une déclaration');

        return response()->json((new DeclarationResource(
            $declaration->load(['items.documentType', 'citizenProfile', 'organization', 'recordedBy', 'certificat', 'territory'])
        ))->resolve($request));
    }

    public function cancel(Request $request, DeclarationDePerte $declaration)
    {
        Gate::authorize('cancel', $declaration);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        if (! $declaration->isCancellable()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette déclaration ne peut plus être annulée (statut : ' . $declaration->status . ').',
            ], 409);
        }

        $declaration = $this->declarations->cancel($declaration, $request->user(), $data['reason']);

        return response()->json((new DeclarationResource($declaration))->resolve($request));
    }

    /** PDF du certificat (agent habilité). */
    public function certificate(DeclarationDePerte $declaration)
    {
        Gate::authorize('view', $declaration);

        $path = PrivateFiles::path($declaration->certificat?->pdf_path);
        abort_unless($path, 404, 'Certificat introuvable.');

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $declaration->certificat->certificat_number . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}

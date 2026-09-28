<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\StoreOrganizationRequest;
use App\Http\Requests\V2\UpdateOrganizationRequest;
use App\Http\Resources\V2\OrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Territory;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationAccess $access) {}

    /**
     * Organisations visibles par l'utilisateur (son périmètre uniquement).
     * Filtres : type, level, parent, territory, q, include_inactive (super_admin).
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Organization::class);
        $user = $request->user();

        $query = $this->access->visibleOrganizations($user)
            ->with(['type', 'parent', 'territory'])
            ->withCount(['children', 'activeMemberships']);

        if (! ($user->isSuperAdmin() && $request->boolean('include_inactive'))) {
            $query->where('is_active', true);
        }
        if ($type = $request->query('type')) {
            $query->whereHas('type', fn ($q) => $q->where('code', $type));
        }
        if ($level = $request->query('level')) {
            $query->whereHas('type', fn ($q) => $q->where('level', $level));
        }
        if ($parent = $request->query('parent')) {
            $query->whereHas('parent', fn ($q) => $q->where('uuid', $parent));
        }
        if ($territory = $request->query('territory')) {
            $query->whereHas('territory', fn ($q) => $q->where('code', $territory));
        }
        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        return OrganizationResource::collection($query->orderBy('path')->paginate(50));
    }

    public function show(Organization $organization)
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource(
            $organization->load(['type', 'parent', 'territory'])->loadCount(['children', 'activeMemberships'])
        );
    }

    public function store(StoreOrganizationRequest $request)
    {
        $data   = $request->validated();
        $type   = OrganizationType::where('code', $data['organization_type'])->firstOrFail();
        $parent = isset($data['parent_uuid']) ? Organization::where('uuid', $data['parent_uuid'])->firstOrFail() : null;

        if ($type->level === OrganizationType::LEVEL_INSTITUTION) {
            if ($parent) {
                throw ValidationException::withMessages(['parent_uuid' => 'Une institution est une racine : elle n\'a pas de parent.']);
            }
            Gate::authorize('createRoot', Organization::class);
        } else {
            if (! $parent) {
                throw ValidationException::withMessages(['parent_uuid' => 'Cette organisation doit être rattachée à une organisation parente.']);
            }
            Gate::authorize('createChild', $parent);
            $this->assertParentCanHaveChildren($parent);
        }

        $organization = Organization::create([
            'parent_id'            => $parent?->id,
            'organization_type_id' => $type->id,
            'name'                 => $data['name'],
            'code'                 => $data['code'] ?? null,
            'territory_id'         => $this->territoryId($data['territory_code'] ?? null),
            'address'              => $data['address'] ?? null,
            'phone'                => $data['phone'] ?? null,
            'email'                => $data['email'] ?? null,
        ]);

        return (new OrganizationResource($organization->fresh(['type', 'parent', 'territory'])))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization)
    {
        Gate::authorize('update', $organization);
        $data = $request->validated();

        if (array_key_exists('parent_uuid', $data)) {
            if (! $request->user()->isSuperAdmin()) {
                abort(403, 'Seul le super administrateur peut changer le rattachement d\'une organisation.');
            }
            $parent = $data['parent_uuid'] ? Organization::where('uuid', $data['parent_uuid'])->firstOrFail() : null;
            $this->assertValidParent($organization, $parent);
            $organization->parent_id = $parent?->id;
        }

        if (array_key_exists('territory_code', $data)) {
            $organization->territory_id = $this->territoryId($data['territory_code']);
        }

        $organization->fill(collect($data)->only(['name', 'code', 'address', 'phone', 'email'])->all());
        $organization->save();

        return new OrganizationResource($organization->fresh(['type', 'parent', 'territory']));
    }

    public function deactivate(Organization $organization)
    {
        Gate::authorize('toggle', $organization);
        $organization->update(['is_active' => false]);

        return new OrganizationResource($organization->fresh(['type', 'parent', 'territory']));
    }

    public function activate(Organization $organization)
    {
        Gate::authorize('toggle', $organization);
        $organization->update(['is_active' => true]);

        return new OrganizationResource($organization->fresh(['type', 'parent', 'territory']));
    }

    // ── Règles de structure de l'arbre ───────────────

    private function assertParentCanHaveChildren(Organization $parent): void
    {
        if (! $parent->is_active) {
            throw ValidationException::withMessages(['parent_uuid' => 'L\'organisation parente est désactivée.']);
        }
        if ($parent->type->level === OrganizationType::LEVEL_STRUCTURE) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une structure (commissariat, brigade…) ne peut pas avoir de sous-organisation.']);
        }
    }

    private function assertValidParent(Organization $organization, ?Organization $parent): void
    {
        $isInstitution = $organization->type->level === OrganizationType::LEVEL_INSTITUTION;

        if ($isInstitution && $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une institution est une racine : elle n\'a pas de parent.']);
        }
        if (! $isInstitution && ! $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Cette organisation doit avoir un parent.']);
        }
        if ($parent) {
            if ($parent->id === $organization->id || $parent->isDescendantOf($organization)) {
                throw ValidationException::withMessages(['parent_uuid' => 'Une organisation ne peut pas être rattachée à elle-même ou à sa descendance.']);
            }
            $this->assertParentCanHaveChildren($parent);
        }
    }

    private function territoryId(?string $code): ?int
    {
        return $code ? Territory::where('code', $code)->value('id') : null;
    }
}

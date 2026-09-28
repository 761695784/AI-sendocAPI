<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\StoreMembershipRequest;
use App\Http\Requests\V2\UpdateMembershipRequest;
use App\Http\Resources\V2\MembershipResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comptes agents : chaque agent a son propre compte nominatif,
 * rattaché à une ou plusieurs organisations avec un rôle dans chacune.
 */
class MembershipController extends Controller
{
    public function __construct(private OrganizationAccess $access) {}

    public function index(Request $request, Organization $organization)
    {
        Gate::authorize('viewMembers', $organization);

        $memberships = $organization->memberships()
            ->with('user')
            ->when(! $request->boolean('include_ended'), fn ($q) => $q->active())
            ->orderBy('role')
            ->get();

        return response()->json(MembershipResource::collection($memberships)->resolve());
    }

    public function store(StoreMembershipRequest $request, Organization $organization)
    {
        Gate::authorize('manageMembers', $organization);
        $actor = $request->user();
        $data  = $request->validated();

        $this->assertCanAssign($actor, $organization, $data['role']);
        $this->assertRoleFitsOrganization($data['role'], $organization);

        $invite = false;

        $membership = DB::transaction(function () use ($data, $organization, $actor, &$invite) {
            $user = User::withTrashed()->where('email', $data['email'])->first();

            if ($user && ($user->trashed() || $user->account_type === User::TYPE_CITIZEN)) {
                throw ValidationException::withMessages([
                    'email' => 'Cet email appartient à un compte citoyen ou supprimé. Utilisez l\'adresse professionnelle de l\'agent.',
                ]);
            }

            if (! $user) {
                $user = User::create([
                    'FirstName'         => $data['FirstName'],
                    'LastName'          => $data['LastName'],
                    'Adress'            => $organization->name,
                    'Phone'             => $data['Phone'],
                    'email'             => $data['email'],
                    'password'          => Hash::make(Str::random(40)), // défini par l'agent via l'invitation
                    'account_type'      => User::TYPE_AGENT,
                    'status'            => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ]);
                $invite = true;
            }

            $existing = OrganizationMembership::where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->first();

            if ($existing && $existing->isActive()) {
                throw ValidationException::withMessages(['email' => 'Cet agent est déjà rattaché à cette organisation.']);
            }

            $hasPrimary = $user->activeMemberships()->where('is_primary', true)->exists();
            $isPrimary  = ($data['is_primary'] ?? false) || ! $hasPrimary;
            if ($isPrimary) {
                $user->memberships()->update(['is_primary' => false]);
            }

            $attributes = [
                'role'       => $data['role'],
                'matricule'  => $data['matricule'] ?? null,
                'position'   => $data['position'] ?? null,
                'is_primary' => $isPrimary,
                'starts_at'  => now(),
                'ends_at'    => null,
                'created_by' => $actor->id,
            ];

            // Réaffectation dans une organisation déjà connue : on réactive la ligne.
            $membership = $existing
                ? tap($existing)->update($attributes)
                : OrganizationMembership::create($attributes + ['user_id' => $user->id, 'organization_id' => $organization->id]);

            if ($user->status !== User::STATUS_ACTIVE) {
                $user->update(['status' => User::STATUS_ACTIVE]);
            }

            return $membership;
        });

        if ($invite) {
            // Invitation = lien de définition du mot de passe (même mécanisme que "mot de passe oublié").
            try {
                Password::sendResetLink(['email' => $membership->user->email]);
            } catch (\Throwable $e) {
                Log::error('Invitation agent non envoyée : ' . $e->getMessage());
            }
        }

        return response()->json(
            (new MembershipResource($membership->load(['user', 'organization'])))->resolve() + ['invitation_sent' => $invite],
            201
        );
    }

    public function update(UpdateMembershipRequest $request, OrganizationMembership $membership)
    {
        $actor = $request->user();
        $this->assertCanManage($actor, $membership);
        $data = $request->validated();

        if (isset($data['role']) && $data['role'] !== $membership->role) {
            $this->assertCanAssign($actor, $membership->organization, $data['role']);
            $this->assertRoleFitsOrganization($data['role'], $membership->organization);
        }

        DB::transaction(function () use ($membership, $data) {
            if (! empty($data['is_primary'])) {
                OrganizationMembership::where('user_id', $membership->user_id)->update(['is_primary' => false]);
            }
            $membership->update($data);
        });

        return response()->json((new MembershipResource($membership->fresh(['user', 'organization'])))->resolve());
    }

    /**
     * Fin d'affectation (jamais de suppression : l'historique reste auditable).
     * Un agent sans plus aucune affectation active est suspendu.
     */
    public function end(Request $request, OrganizationMembership $membership)
    {
        $actor = $request->user();
        $this->assertCanManage($actor, $membership);

        if (! $membership->isActive()) {
            return response()->json(['success' => false, 'message' => 'Cette affectation est déjà terminée.'], 409);
        }

        DB::transaction(function () use ($membership) {
            $membership->update(['ends_at' => now(), 'is_primary' => false]);

            $user = $membership->user;
            $next = $user->activeMemberships()->first();

            if ($next) {
                if (! $user->activeMemberships()->where('is_primary', true)->exists()) {
                    $next->update(['is_primary' => true]);
                }
            } elseif ($user->account_type === User::TYPE_AGENT) {
                $user->update(['status' => User::STATUS_SUSPENDED]);
            }
        });

        $this->access->forget($membership->user);

        return response()->json((new MembershipResource($membership->fresh(['user', 'organization'])))->resolve());
    }

    // ── Règles ───────────────────────────────────────

    private function assertCanManage(User $actor, OrganizationMembership $membership): void
    {
        Gate::authorize('manageMembers', $membership->organization);

        if (! $actor->isSuperAdmin()) {
            if ($membership->user_id === $actor->id) {
                abort(403, 'Vous ne pouvez pas modifier votre propre affectation.');
            }
            // On ne gère que des rôles que l'on pourrait soi-même attribuer.
            if (! in_array($membership->role, $this->access->assignableRoles($actor, $membership->organization), true)) {
                abort(403, 'Vous ne pouvez pas gérer un agent de ce niveau.');
            }
        }
    }

    private function assertCanAssign(User $actor, Organization $organization, string $role): void
    {
        if (! in_array($role, $this->access->assignableRoles($actor, $organization), true)) {
            abort(403, 'Vous ne pouvez pas attribuer ce rôle.');
        }
    }

    private function assertRoleFitsOrganization(string $role, Organization $organization): void
    {
        $level = $organization->type->level;

        $ok = match ($role) {
            OrganizationMembership::ROLE_INSTITUTION_ADMIN => $level !== OrganizationType::LEVEL_STRUCTURE,
            OrganizationMembership::ROLE_STRUCTURE_MANAGER => $level === OrganizationType::LEVEL_STRUCTURE,
            default                                        => true,
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'role' => $role === OrganizationMembership::ROLE_INSTITUTION_ADMIN
                    ? 'Un administrateur institutionnel se rattache à une institution ou une direction, pas à une structure.'
                    : 'Un responsable de structure se rattache à une structure (commissariat, brigade…).',
            ]);
        }
    }
}

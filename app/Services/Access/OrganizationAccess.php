<?php

namespace App\Services\Access;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Point unique de décision : "cet utilisateur peut-il faire X dans l'organisation Y ?"
 *
 * - super_admin : tout, partout.
 * - institution_admin sur une organisation : cette organisation + toute sa descendance.
 * - structure_manager / agent : uniquement leur organisation.
 * - Organisation désactivée ou affectation terminée : aucun droit (sauf super_admin).
 */
class OrganizationAccess
{
    /** @var array<int, Collection> cache par utilisateur pour la requête en cours */
    private array $membershipCache = [];

    public function memberships(User $user): Collection
    {
        return $this->membershipCache[$user->id] ??= $user->activeMemberships()
            ->with('organization')
            ->get()
            ->filter(fn (OrganizationMembership $m) => $m->organization && $m->organization->is_active);
    }

    public function forget(User $user): void
    {
        unset($this->membershipCache[$user->id]);
    }

    /**
     * Rôle le plus élevé de l'utilisateur qui s'applique à cette organisation, ou null.
     */
    public function effectiveRole(User $user, Organization $organization): ?string
    {
        $inherited = config('doctrack_access.inherited_roles', []);
        $best      = null;

        foreach ($this->memberships($user) as $membership) {
            $applies = $membership->organization_id === $organization->id
                || (in_array($membership->role, $inherited, true) && $organization->isDescendantOf($membership->organization));

            if ($applies && ($best === null || OrganizationMembership::rank($membership->role) < OrganizationMembership::rank($best))) {
                $best = $membership->role;
            }
        }

        return $best;
    }

    public function can(User $user, string $ability, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $user->isActive() || ! $organization->is_active) {
            return false;
        }

        $role = $this->effectiveRole($user, $organization);

        return $role !== null && in_array($ability, config("doctrack_access.abilities.{$role}", []), true);
    }

    /**
     * Rôles que l'utilisateur peut attribuer dans cette organisation.
     */
    public function assignableRoles(User $user, Organization $organization): array
    {
        if ($user->isSuperAdmin()) {
            return OrganizationMembership::ROLES;
        }

        $role = $this->effectiveRole($user, $organization);

        return $role ? config("doctrack_access.assignable_roles.{$role}", []) : [];
    }

    /**
     * Requête des organisations visibles par l'utilisateur.
     */
    public function visibleOrganizations(User $user): Builder
    {
        $query = Organization::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        $memberships = $this->memberships($user);
        if ($memberships->isEmpty() || ! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        $inherited = config('doctrack_access.inherited_roles', []);

        return $query->where('is_active', true)->where(function (Builder $q) use ($memberships, $inherited) {
            foreach ($memberships as $membership) {
                if (in_array($membership->role, $inherited, true)) {
                    $q->orWhere('path', 'like', $membership->organization->path . '%');
                } else {
                    $q->orWhere('id', $membership->organization_id);
                }
            }
        });
    }

    /**
     * Ids des organisations où l'utilisateur détient ce droit
     * (null = toutes : super_admin).
     */
    public function organizationIdsWithAbility(User $user, string $ability): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        return $this->visibleOrganizations($user)->get()
            ->filter(fn (Organization $org) => $this->can($user, $ability, $org))
            ->pluck('id')
            ->all();
    }

    /** Ids des organisations visibles (pour filtrer d'autres tables). */
    public function visibleOrganizationIds(User $user): array
    {
        return $this->visibleOrganizations($user)->pluck('id')->all();
    }

    /**
     * Organisation "active" de la requête : celle demandée si l'utilisateur y a
     * un rôle, sinon son affectation principale, sinon la première.
     */
    public function resolveActive(User $user, ?string $requestedUuid): ?Organization
    {
        $memberships = $this->memberships($user);

        if ($requestedUuid) {
            $org = Organization::where('uuid', $requestedUuid)->first();

            return $org && ($user->isSuperAdmin() || $this->effectiveRole($user, $org)) ? $org : null;
        }

        $primary = $memberships->firstWhere('is_primary', true) ?? $memberships->first();

        return $primary?->organization;
    }
}

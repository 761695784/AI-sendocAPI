<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\Access\OrganizationAccess;

class OrganizationPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->memberships($user)->isNotEmpty();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->access->can($user, 'organizations.view', $organization);
    }

    /** Créer une institution racine : super_admin uniquement. */
    public function createRoot(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** Créer une organisation sous $parent. */
    public function createChild(User $user, Organization $parent): bool
    {
        return $this->access->can($user, 'organizations.create_child', $parent);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->access->can($user, 'organizations.update', $organization);
    }

    /**
     * Activer / désactiver : droit requis sur le PARENT (on ne peut pas
     * désactiver l'organisation qui nous donne nos propres droits).
     */
    public function toggle(User $user, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $organization->parent
            && $this->access->can($user, 'organizations.toggle', $organization->parent);
    }

    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->access->can($user, 'members.view', $organization);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->access->can($user, 'members.manage', $organization);
    }
}

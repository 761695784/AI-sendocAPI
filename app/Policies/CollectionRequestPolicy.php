<?php

namespace App\Policies;

use App\Models\CollectionRequest;
use App\Models\User;
use App\Services\Access\OrganizationAccess;

/**
 * Une collecte est traitée par la structure à laquelle elle est affectée
 * (et supervisée par le responsable de son institution).
 */
class CollectionRequestPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->memberships($user)
            ->contains(fn ($m) => in_array('collections.view', config("doctrack_access.abilities.{$m->role}", []), true));
    }

    public function view(User $user, CollectionRequest $request): bool
    {
        return $user->isSuperAdmin()
            || ($request->organization && $this->access->can($user, 'collections.view', $request->organization));
    }

    public function manage(User $user, CollectionRequest $request): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($request->organization && $this->access->can($user, 'collections.manage', $request->organization));
    }
}

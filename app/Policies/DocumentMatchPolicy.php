<?php

namespace App\Policies;

use App\Models\DocumentMatch;
use App\Models\User;
use App\Services\Access\OrganizationAccess;

/**
 * La revue d'une correspondance revient à la structure qui détient le
 * document (sinon celle qui a enregistré la déclaration).
 */
class DocumentMatchPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->memberships($user)
            ->contains(fn ($m) => in_array('matches.view', config("doctrack_access.abilities.{$m->role}", []), true));
    }

    public function view(User $user, DocumentMatch $match): bool
    {
        return $user->isSuperAdmin()
            || ($match->organization && $this->access->can($user, 'matches.view', $match->organization));
    }

    public function review(User $user, DocumentMatch $match): bool
    {
        if (! $match->isOpen()) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($match->organization && $this->access->can($user, 'matches.review', $match->organization));
    }
}

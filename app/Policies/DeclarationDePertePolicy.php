<?php

namespace App\Policies;

use App\Http\Middleware\ResolveActiveOrganization;
use App\Models\DeclarationDePerte;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Access\OrganizationAccess;

/**
 * Déclarations institutionnelles (API V2).
 * Les routes V1 du citoyen gardent leurs propres contrôles (propriétaire).
 */
class DeclarationDePertePolicy
{
    public function __construct(private OrganizationAccess $access) {}

    /** Enregistrer une déclaration au guichet de $structure. */
    public function createAtCounter(User $user, Organization $structure): bool
    {
        return $structure->type?->level === OrganizationType::LEVEL_STRUCTURE
            && ! $user->isSuperAdmin() // le super admin n'est pas un agent de guichet
            && $this->access->can($user, 'declarations.create', $structure);
    }

    public function viewInstitutional(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->memberships($user)
            ->contains(fn ($m) => in_array('declarations.view', config("doctrack_access.abilities.{$m->role}", []), true));
    }

    public function view(User $user, DeclarationDePerte $declaration): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $declaration->organization
            && $this->access->can($user, 'declarations.view', $declaration->organization);
    }

    public function cancel(User $user, DeclarationDePerte $declaration): bool
    {
        if (! $declaration->isCounter() || ! $declaration->organization) {
            return false;
        }
        if ($this->access->can($user, 'declarations.cancel', $declaration->organization)) {
            return true;
        }

        // L'agent auteur peut corriger sa propre erreur, peu de temps après.
        return $declaration->recorded_by_user_id === $user->id
            && $this->access->can($user, 'declarations.create', $declaration->organization)
            && $declaration->created_at->gt(now()->subHours(config('doctrack.agent_cancel_window_hours')));
    }

    public function searchCitizens(User $user): bool
    {
        return $this->hasAbilityInActiveOrganization($user, 'citizens.search');
    }

    public function createCitizen(User $user): bool
    {
        return $this->hasAbilityInActiveOrganization($user, 'citizens.create');
    }

    private function hasAbilityInActiveOrganization(User $user, string $ability): bool
    {
        $org = ResolveActiveOrganization::current();

        return $org && $this->access->can($user, $ability, $org) && ! $user->isSuperAdmin();
    }
}

<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Access\OrganizationAccess;

/**
 * Documents trouvés côté institutionnel (API V2).
 * Les routes V1 du citoyen gardent leurs propres contrôles (publicateur).
 */
class DocumentPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function depositAtCounter(User $user, Organization $structure): bool
    {
        return $structure->type?->level === OrganizationType::LEVEL_STRUCTURE
            && ! $user->isSuperAdmin()
            && $this->access->can($user, 'found_documents.create', $structure);
    }

    public function viewInstitutional(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->memberships($user)
            ->contains(fn ($m) => in_array('found_documents.view', config("doctrack_access.abilities.{$m->role}", []), true));
    }

    public function viewCustody(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $document->custodyOrganization
            && $this->access->can($user, 'found_documents.view', $document->custodyOrganization);
    }

    /**
     * Supprimer définitivement un document enregistré au guichet par la structure
     * (erreur de saisie, doublon). Pas pour un document publié par un citoyen.
     */
    public function destroyCustody(User $user, Document $document): bool
    {
        return $document->found_channel === Document::CHANNEL_COUNTER
            && $document->custodyOrganization
            && ! $user->isSuperAdmin()
            && $this->access->can($user, 'found_documents.create', $document->custodyOrganization);
    }

    /** Prendre en garde un document publié en ligne (le trouveur l'apporte). */
    public function takeCustody(User $user, Document $document, Organization $structure): bool
    {
        return $document->found_channel === Document::CHANNEL_CITIZEN
            && ! $document->isInCustody()
            && in_array($document->lifecycle_status, Document::MATCHABLE, true)
            && $this->depositAtCounter($user, $structure);
    }
}

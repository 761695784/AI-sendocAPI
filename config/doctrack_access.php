<?php

/*
|--------------------------------------------------------------------------
| Droits des rôles d'organisation
|--------------------------------------------------------------------------
| Un rôle d'organisation est porté par organization_memberships.role.
| - institution_admin : s'applique à l'organisation ET à toute sa descendance
| - structure_manager et agent : uniquement à leur propre organisation
|
| Modifier cette table suffit à ajuster les droits, sans toucher au code.
*/

return [
    'abilities' => [
        'institution_admin' => [
            'organizations.view',
            'organizations.create_child',
            'organizations.update',
            'organizations.toggle',
            'members.view',
            'members.manage',
            'stats.view',
            'audit.view',
            'declarations.view',
            'declarations.cancel',
            'declarations.override',
            'citizens.search',
            'found_documents.view',
            'matches.view',
            'matches.review',
            'collections.view',
            'collections.manage',
            'restitutions.view',
            'restitutions.void',
            'restitutions.override',
            'billing.view',
            'billing.exempt',
            'vigilance.view',
            'vigilance.review',
        ],
        'structure_manager' => [
            'organizations.view',
            'organizations.update',
            'members.view',
            'members.manage',
            'stats.view',
            'audit.view',
            'declarations.create',
            'declarations.view',
            'declarations.cancel',
            'declarations.override',
            'citizens.search',
            'citizens.create',
            'found_documents.create',
            'found_documents.view',
            'matches.view',
            'matches.review',
            'collections.view',
            'collections.manage',
            'restitutions.view',
            'restitutions.perform',
            'restitutions.void',
            'restitutions.override',
            'billing.view',
            'billing.collect',
            'billing.exempt',
            'vigilance.view',
            'vigilance.review',
        ],
        'agent' => [
            'organizations.view',
            'declarations.create',
            'declarations.view',
            'citizens.search',
            'citizens.create',
            'found_documents.create',
            'found_documents.view',
            'matches.view',
            'matches.review',
            'collections.view',
            'collections.manage',
            'restitutions.view',
            'restitutions.perform',
            'billing.collect',
            // Un agent peut annuler SA propre saisie pendant un délai limité
            // (config doctrack.agent_cancel_window_hours) : règle dans DeclarationDePertePolicy.
        ],
    ],

    // Rôles qu'un rôle donné peut attribuer (dans son périmètre).
    'assignable_roles' => [
        'institution_admin' => ['institution_admin', 'structure_manager', 'agent'],
        'structure_manager' => ['agent'],
        'agent'             => [],
    ],

    // Rôles dont le périmètre couvre toute la descendance.
    'inherited_roles' => ['institution_admin'],
];

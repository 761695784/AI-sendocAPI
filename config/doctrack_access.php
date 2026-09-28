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
            'citizens.search',
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
            'citizens.search',
            'citizens.create',
        ],
        'agent' => [
            'organizations.view',
            'declarations.create',
            'declarations.view',
            'citizens.search',
            'citizens.create',
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

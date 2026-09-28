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
        ],
        'structure_manager' => [
            'organizations.view',
            'organizations.update',
            'members.view',
            'members.manage',
            'stats.view',
            'audit.view',
        ],
        'agent' => [
            'organizations.view',
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

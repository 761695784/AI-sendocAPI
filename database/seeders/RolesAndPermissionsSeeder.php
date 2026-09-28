<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles GLOBAUX de la plateforme (Spatie).
 * Les rôles d'agent (institution_admin, structure_manager, agent) sont portés
 * par organization_memberships et définis dans config/doctrack_access.php.
 *
 * Idempotent : peut être relancé sans erreur.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'publish documents',
            'declare lost documents',
            'change status when owner find his doc',
            'cancel a lost declaration',
            'comment a publication',
            'manage users',
            'manage roles',
            'view audit',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        Role::firstOrCreate(['name' => User::ROLE_SUPER_ADMIN, 'guard_name' => 'api'])
            ->syncPermissions(['publish documents', 'declare lost documents', 'manage users', 'manage roles', 'view audit']);

        Role::firstOrCreate(['name' => User::ROLE_CITIZEN, 'guard_name' => 'api'])
            ->syncPermissions([
                'publish documents',
                'declare lost documents',
                'change status when owner find his doc',
                'cancel a lost declaration',
                'comment a publication',
            ]);

        Role::firstOrCreate(['name' => User::ROLE_AUDITOR, 'guard_name' => 'api'])
            ->syncPermissions(['view audit']);
    }
}

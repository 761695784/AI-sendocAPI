<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Idempotent : peut être relancé sans erreur.
     */
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
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'api'])
            ->syncPermissions(['publish documents', 'declare lost documents', 'manage users', 'manage roles']);

        Role::firstOrCreate(['name' => 'SimpleUser', 'guard_name' => 'api'])
            ->syncPermissions([
                'publish documents',
                'declare lost documents',
                'change status when owner find his doc',
                'cancel a lost declaration',
                'comment a publication',
            ]);
    }
}

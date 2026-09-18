<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
           // Réinitialiser les permissions et rôles en cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

              // Create permissions
              Permission::create(['name' => 'publish documents']);
              Permission::create(['name' => 'declare lost documents']);
              Permission::create(['name' => 'change status when owner find his doc']);
              Permission::create(['name' => 'cancel a lost declaration']);
              Permission::create(['name' => 'comment a publication']);
              Permission::create(['name' => 'manage users']);
              Permission::create(['name' => 'manage roles']);

              // Create roles and assign permissions
              $role = Role::create(['name' => 'Admin']);
              $role->givePermissionTo('publish documents', 'declare lost documents', 'manage users', 'manage roles');

              $role = Role::create(['name' => 'SimpleUser']);
              $role->givePermissionTo('publish documents', 'declare lost documents','change status when owner find his doc', 'cancel a lost declaration', 'comment a publication');
          }
    }


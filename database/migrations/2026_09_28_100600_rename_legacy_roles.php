<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admin → super_admin, SimpleUser → citizen.
 * Les anciens noms restent renvoyés au front par /login et /me (compatibilité).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'Admin')->update(['name' => 'super_admin']);
        DB::table('roles')->where('name', 'SimpleUser')->update(['name' => 'citizen']);

        $adminIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->pluck('model_has_roles.model_id');

        DB::table('users')->whereIn('id', $adminIds)->update(['account_type' => 'admin']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'super_admin')->update(['name' => 'Admin']);
        DB::table('roles')->where('name', 'citizen')->update(['name' => 'SimpleUser']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};

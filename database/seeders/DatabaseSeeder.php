<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Ordre obligatoire : rôles → types de documents → utilisateurs.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DocumentTypeSeeder::class,
            UserSeeder::class,
        ]);
    }
}

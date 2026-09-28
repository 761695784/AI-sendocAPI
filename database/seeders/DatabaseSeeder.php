<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Ordre obligatoire : rôles → référentiels → utilisateurs → organisations.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DocumentTypeSeeder::class,
            TerritorySeeder::class,
            OrganizationTypeSeeder::class,
            UserSeeder::class,
            OrganizationSeeder::class,
            BillingSeeder::class,
        ]);
    }
}

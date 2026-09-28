<?php

namespace Database\Seeders;

use App\Models\OrganizationType;
use Illuminate\Database\Seeder;

class OrganizationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'police_nationale',        'name' => 'Police nationale',        'level' => OrganizationType::LEVEL_INSTITUTION],
            ['code' => 'gendarmerie_nationale',   'name' => 'Gendarmerie nationale',   'level' => OrganizationType::LEVEL_INSTITUTION],
            ['code' => 'direction_regionale',     'name' => 'Direction / Légion régionale', 'level' => OrganizationType::LEVEL_INTERMEDIATE],
            ['code' => 'commissariat',            'name' => 'Commissariat',            'level' => OrganizationType::LEVEL_STRUCTURE],
            ['code' => 'brigade',                 'name' => 'Brigade de gendarmerie',  'level' => OrganizationType::LEVEL_STRUCTURE],
            ['code' => 'poste',                   'name' => 'Poste de police',         'level' => OrganizationType::LEVEL_STRUCTURE],
        ];

        foreach ($types as $type) {
            OrganizationType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}

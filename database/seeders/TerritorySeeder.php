<?php

namespace Database\Seeders;

use App\Models\Territory;
use Illuminate\Database\Seeder;

/**
 * 14 régions et 46 départements du Sénégal.
 * Codes internes DocTrack (stables, indépendants des libellés).
 */
class TerritorySeeder extends Seeder
{
    private const REGIONS = [
        'DK' => ['Dakar', ['Dakar', 'Guédiawaye', 'Keur Massar', 'Pikine', 'Rufisque']],
        'DB' => ['Diourbel', ['Bambey', 'Diourbel', 'Mbacké']],
        'FK' => ['Fatick', ['Fatick', 'Foundiougne', 'Gossas']],
        'KA' => ['Kaffrine', ['Birkelane', 'Kaffrine', 'Koungheul', 'Malem-Hodar']],
        'KL' => ['Kaolack', ['Guinguinéo', 'Kaolack', 'Nioro du Rip']],
        'KE' => ['Kédougou', ['Kédougou', 'Salémata', 'Saraya']],
        'KD' => ['Kolda', ['Kolda', 'Médina Yoro Foulah', 'Vélingara']],
        'LG' => ['Louga', ['Kébémer', 'Linguère', 'Louga']],
        'MT' => ['Matam', ['Kanel', 'Matam', 'Ranérou-Ferlo']],
        'SL' => ['Saint-Louis', ['Dagana', 'Podor', 'Saint-Louis']],
        'SE' => ['Sédhiou', ['Bounkiling', 'Goudomp', 'Sédhiou']],
        'TC' => ['Tambacounda', ['Bakel', 'Goudiry', 'Koumpentoum', 'Tambacounda']],
        'TH' => ['Thiès', ['Mbour', 'Thiès', 'Tivaouane']],
        'ZG' => ['Ziguinchor', ['Bignona', 'Oussouye', 'Ziguinchor']],
    ];

    public function run(): void
    {
        foreach (self::REGIONS as $code => [$name, $departments]) {
            $region = Territory::updateOrCreate(
                ['code' => 'SN-' . $code],
                ['name' => $name, 'level' => Territory::LEVEL_REGION, 'parent_id' => null]
            );

            foreach ($departments as $department) {
                Territory::updateOrCreate(
                    ['code' => 'SN-' . $code . '-' . self::slug($department)],
                    ['name' => $department, 'level' => Territory::LEVEL_DEPARTMENT, 'parent_id' => $region->id]
                );
            }
        }
    }

    private static function slug(string $name): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', \Illuminate\Support\Str::ascii($name)), 0, 6));
    }
}

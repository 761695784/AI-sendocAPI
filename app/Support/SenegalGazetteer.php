<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Référentiel des 46 départements du Sénégal (chef-lieu, coordonnées approximatives)
 * et des lieux courants qui s'y rattachent. Sert à placer les publications sur la carte
 * sans dépendre d'un service de géocodage externe.
 */
final class SenegalGazetteer
{
    /** Département => [région, latitude, longitude] (chef-lieu). */
    public const DEPARTMENTS = [
        'Dakar'              => ['Dakar', 14.6928, -17.4467],
        'Guédiawaye'         => ['Dakar', 14.7769, -17.3936],
        'Keur Massar'        => ['Dakar', 14.7833, -17.3167],
        'Pikine'             => ['Dakar', 14.7547, -17.3906],
        'Rufisque'           => ['Dakar', 14.7158, -17.2733],
        'Bambey'             => ['Diourbel', 14.6983, -16.4517],
        'Diourbel'           => ['Diourbel', 14.6553, -16.2314],
        'Mbacké'             => ['Diourbel', 14.7908, -15.9083],
        'Fatick'             => ['Fatick', 14.3390, -16.4111],
        'Foundiougne'        => ['Fatick', 14.1333, -16.4667],
        'Gossas'             => ['Fatick', 14.4950, -16.0661],
        'Birkelane'          => ['Kaffrine', 14.1333, -15.7500],
        'Kaffrine'           => ['Kaffrine', 14.1059, -15.5508],
        'Koungheul'          => ['Kaffrine', 13.9833, -14.8000],
        'Malem-Hodar'        => ['Kaffrine', 14.0833, -15.3000],
        'Guinguinéo'         => ['Kaolack', 14.2667, -15.9500],
        'Kaolack'            => ['Kaolack', 14.1652, -16.0726],
        'Nioro du Rip'       => ['Kaolack', 13.7500, -15.8000],
        'Kédougou'           => ['Kédougou', 12.5556, -12.1744],
        'Salémata'           => ['Kédougou', 12.6333, -12.8167],
        'Saraya'             => ['Kédougou', 12.8333, -11.7500],
        'Kolda'              => ['Kolda', 12.8939, -14.9406],
        'Médina Yoro Foulah' => ['Kolda', 13.2833, -14.7167],
        'Vélingara'          => ['Kolda', 13.1500, -14.1167],
        'Kébémer'            => ['Louga', 15.3667, -16.4500],
        'Linguère'           => ['Louga', 15.3833, -15.1167],
        'Louga'              => ['Louga', 15.6144, -16.2286],
        'Kanel'              => ['Matam', 15.4833, -13.1833],
        'Matam'              => ['Matam', 15.6559, -13.2554],
        'Ranérou-Ferlo'      => ['Matam', 15.3000, -13.9667],
        'Dagana'             => ['Saint-Louis', 16.5167, -15.5000],
        'Podor'              => ['Saint-Louis', 16.6500, -14.9667],
        'Saint-Louis'        => ['Saint-Louis', 16.0326, -16.4818],
        'Bounkiling'         => ['Sédhiou', 13.0333, -15.7000],
        'Goudomp'            => ['Sédhiou', 12.5833, -15.8667],
        'Sédhiou'            => ['Sédhiou', 12.7081, -15.5569],
        'Bakel'              => ['Tambacounda', 14.9000, -12.4667],
        'Goudiry'            => ['Tambacounda', 14.1833, -12.7167],
        'Koumpentoum'        => ['Tambacounda', 13.9833, -14.5500],
        'Tambacounda'        => ['Tambacounda', 13.7699, -13.6673],
        'Mbour'              => ['Thiès', 14.4198, -16.9696],
        'Thiès'              => ['Thiès', 14.7910, -16.9359],
        'Tivaouane'          => ['Thiès', 14.9500, -16.8167],
        'Bignona'            => ['Ziguinchor', 12.8103, -16.2264],
        'Oussouye'           => ['Ziguinchor', 12.4850, -16.5469],
        'Ziguinchor'         => ['Ziguinchor', 12.5833, -16.2719],
    ];

    /** Lieux courants (quartiers, villes) => département. */
    public const PLACES = [
        // Département de Dakar
        'plateau' => 'Dakar', 'sandaga' => 'Dakar', 'medina' => 'Dakar', 'gueule tapee' => 'Dakar', 'fann' => 'Dakar',
        'point e' => 'Dakar', 'ucad' => 'Dakar', 'mermoz' => 'Dakar', 'sacre coeur' => 'Dakar', 'ouakam' => 'Dakar',
        'ngor' => 'Dakar', 'yoff' => 'Dakar', 'almadies' => 'Dakar', 'grand yoff' => 'Dakar', 'parcelles assainies' => 'Dakar',
        'parcelles' => 'Dakar', 'hlm' => 'Dakar', 'liberte' => 'Dakar', 'sicap' => 'Dakar', 'colobane' => 'Dakar',
        'grand dakar' => 'Dakar', 'biscuiterie' => 'Dakar', 'hann' => 'Dakar', 'dieuppeul' => 'Dakar', 'cambérène' => 'Dakar',
        'camberene' => 'Dakar', 'patte d oie' => 'Dakar', 'castors' => 'Dakar', 'gorée' => 'Dakar', 'goree' => 'Dakar',
        // Pikine, Guédiawaye, Keur Massar, Rufisque
        'thiaroye' => 'Pikine', 'yeumbeul' => 'Keur Massar', 'malika' => 'Keur Massar', 'mbao' => 'Pikine',
        'diamaguene' => 'Pikine', 'golf sud' => 'Guédiawaye', 'sam notaire' => 'Guédiawaye',
        'diamniadio' => 'Rufisque', 'bargny' => 'Rufisque', 'sebikotane' => 'Rufisque', 'sangalkam' => 'Rufisque',
        'bambilor' => 'Rufisque', 'aibd' => 'Mbour', 'diass' => 'Mbour',
        // Autres villes
        'touba' => 'Mbacké', 'saly' => 'Mbour', 'joal' => 'Mbour', 'somone' => 'Mbour', 'ngaparou' => 'Mbour',
        'pout' => 'Thiès', 'khombole' => 'Thiès', 'mboro' => 'Tivaouane', 'kayar' => 'Thiès', 'cayar' => 'Thiès',
        'richard toll' => 'Dagana', 'ross bethio' => 'Dagana', 'ndioum' => 'Podor', 'ourossogui' => 'Matam',
        'cap skirring' => 'Oussouye', 'karang' => 'Foundiougne', 'sokone' => 'Foundiougne', 'dahra' => 'Linguère',
        'kounguel' => 'Koungheul', 'nioro' => 'Nioro du Rip', 'ranerou' => 'Ranérou-Ferlo', 'medina yoro foula' => 'Médina Yoro Foulah',
    ];

    /** Département reconnu dans un texte libre (« Marché Sandaga, Dakar Plateau » → Dakar), ou null. */
    public static function locate(?string $text): ?string
    {
        $haystack = ' ' . self::normalize((string) $text) . ' ';
        if (trim($haystack) === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;
        foreach (self::terms() as $term => $department) {
            // Le terme le plus long gagne : « Keur Massar, Dakar » → Keur Massar.
            if (strlen($term) > $bestLength && str_contains($haystack, ' ' . $term . ' ')) {
                $best = $department;
                $bestLength = strlen($term);
            }
        }

        return $best;
    }

    /** Département dont le chef-lieu est le plus proche (null si hors du Sénégal). */
    public static function nearest(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null
            || $latitude < 12.0 || $latitude > 16.8 || $longitude < -17.8 || $longitude > -11.2) {
            return null;
        }

        $best = null;
        $bestDistance = INF;
        foreach (self::DEPARTMENTS as $name => [, $lat, $lon]) {
            $distance = ($lat - $latitude) ** 2 + (($lon - $longitude) * cos(deg2rad($latitude))) ** 2;
            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $text)));
    }

    /** @return array<string, string> terme normalisé => département */
    private static function terms(): array
    {
        static $terms = null;
        if ($terms === null) {
            $terms = [];
            foreach (array_keys(self::DEPARTMENTS) as $name) {
                $terms[self::normalize($name)] = $name;
            }
            foreach (self::PLACES as $place => $department) {
                $terms[self::normalize($place)] ??= $department;
            }
        }

        return $terms;
    }
}

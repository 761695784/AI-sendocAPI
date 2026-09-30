<?php

namespace App\Services\Ocr;

/**
 * Numéro de la carte d'identité biométrique CEDEAO (Sénégal) : 17 chiffres
 *   1 chiffre  : sexe (1 = homme, 2 = femme)
 *   2 chiffres : code région
 *   8 chiffres : date de naissance (AAAAMMJJ)
 *   5 chiffres : numéro d'ordre
 *   1 chiffre  : contrôle
 * (structure publiée par la CISR / IRB Canada ; l'algorithme du chiffre de
 * contrôle n'étant pas public, seule la structure est vérifiée.)
 */
class SenegalIdNumber
{
    /** @return array{structure_ok: bool, sex: ?string, region: ?string, birth_date: ?string} */
    public static function analyze(string $number): array
    {
        $digits = preg_replace('/\D/', '', $number);
        $none = ['structure_ok' => false, 'sex' => null, 'region' => null, 'birth_date' => null];

        if (strlen($digits) !== 17) {
            return $none;
        }

        $sex = match ($digits[0]) {
            '1' => 'M',
            '2' => 'F',
            default => null,
        };
        [$y, $m, $d] = [(int) substr($digits, 3, 4), (int) substr($digits, 7, 2), (int) substr($digits, 9, 2)];
        $birthOk = checkdate($m, $d, $y) && $y >= 1900 && sprintf('%04d-%02d-%02d', $y, $m, $d) <= now()->toDateString();

        return [
            'structure_ok' => $sex !== null && $birthOk,
            'sex'          => $sex,
            'region'       => substr($digits, 1, 2),
            'birth_date'   => $birthOk ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null,
        ];
    }
}

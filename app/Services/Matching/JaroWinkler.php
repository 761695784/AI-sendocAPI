<?php

namespace App\Services\Matching;

/**
 * Similarité de Jaro-Winkler (0 = différent, 1 = identique).
 * Adaptée aux noms propres : tolère inversions et fautes de frappe,
 * favorise les préfixes communs.
 */
final class JaroWinkler
{
    public static function similarity(string $a, string $b, float $prefixScale = 0.1): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $window = max(intdiv(max($lenA, $lenB), 2) - 1, 0);
        $matchA = array_fill(0, $lenA, false);
        $matchB = array_fill(0, $lenB, false);
        $matches = 0;

        for ($i = 0; $i < $lenA; $i++) {
            $start = max(0, $i - $window);
            $end   = min($i + $window + 1, $lenB);
            for ($j = $start; $j < $end; $j++) {
                if (! $matchB[$j] && $a[$i] === $b[$j]) {
                    $matchA[$i] = $matchB[$j] = true;
                    $matches++;
                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $lenA; $i++) {
            if (! $matchA[$i]) {
                continue;
            }
            while (! $matchB[$k]) {
                $k++;
            }
            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }
            $k++;
        }

        $jaro = ($matches / $lenA + $matches / $lenB + ($matches - $transpositions / 2) / $matches) / 3;

        $prefix = 0;
        for ($i = 0; $i < min(4, $lenA, $lenB); $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + $prefix * $prefixScale * (1 - $jaro);
    }
}

<?php

namespace App\Services\Matching;

/**
 * Résultat explicable d'une comparaison.
 */
final class MatchResult
{
    /**
     * @param int   $score        0..100
     * @param bool  $numberMatch  même numéro de document (haché) des deux côtés
     * @param float $nameScore    similarité des noms 0..1
     * @param array $details      contribution de chaque critère (affichée à l'agent)
     */
    public function __construct(
        public readonly int $score,
        public readonly bool $numberMatch,
        public readonly float $nameScore,
        public readonly array $details,
    ) {}
}

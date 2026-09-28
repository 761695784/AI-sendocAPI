<?php

namespace App\Services\Matching;

use Illuminate\Support\Str;

/**
 * Normalisation des noms pour la comparaison.
 *
 *  normalize("  N'DIAYÉ ")  → "ndiaye"
 *  key("Ndiaye") = key("Ndiay") = key("Njaay")          (clé phonétique)
 *  key("Mouhamadou") = key("Mohamed") = key("Mamadou")   (table d'équivalences)
 */
class NameNormalizer
{
    /** @var array<string, string>|null variante normalisée → forme canonique */
    private ?array $canonical = null;

    public function normalize(?string $name): string
    {
        $name = Str::lower(Str::ascii((string) $name));
        // Apostrophe = même mot : "N'Diaye" → "ndiaye", "M'Bengue" → "mbengue".
        $name = preg_replace("/['’`´]+/u", '', $name);
        $name = preg_replace('/[^a-z ]+/', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** @return string[] */
    public function tokens(?string $name): array
    {
        $normalized = $this->normalize($name);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * Clé phonétique d'un nom complet (tokens triés : l'ordre des mots
     * composés ne compte pas, "Abdou Aziz" = "Aziz Abdou").
     */
    public function key(?string $name): ?string
    {
        $keys = array_map(fn ($t) => $this->tokenKey($t), $this->tokens($name));
        $keys = array_values(array_filter($keys));
        sort($keys);

        return $keys ? substr(implode('-', $keys), 0, 60) : null;
    }

    public function tokenKey(string $token): string
    {
        $token = $this->canonical($token);

        // Règles de simplification (orthographes wolof / françaises / arabes).
        $rules = [
            '/ou/'          => 'u',
            '/ph/'          => 'f',
            '/th/'          => 't',
            '/kh/'          => 'k',
            '/sh|ch/'       => 's',
            '/dj/'          => 'j',
            '/di(?=[aeiou])/' => 'j',
            '/gu(?=[ei])/'  => 'g',
            '/qu|q|ck|c(?=[aou])|c$/' => 'k',
            '/c(?=[ei])/'   => 's',
            '/y/'           => 'i',
            '/(?<!^)h/'     => '',
            '/([a-z])\1+/'  => '$1',  // lettres doublées
            '/(?<=.)e$/'    => '',    // e final muet
        ];
        foreach ($rules as $pattern => $replacement) {
            $token = preg_replace($pattern, $replacement, $token);
        }

        return $token;
    }

    /** Forme canonique si le mot appartient à un groupe d'équivalences. */
    public function canonical(string $token): string
    {
        if ($this->canonical === null) {
            $this->canonical = [];
            foreach (config('matching.equivalences', []) as $group) {
                $head = $this->normalize($group[0]);
                foreach ($group as $variant) {
                    $this->canonical[$this->normalize($variant)] = $head;
                }
            }
        }

        return $this->canonical[$token] ?? $token;
    }

    /**
     * Similarité de deux noms (0..1), tolérante aux fautes de frappe,
     * aux variantes de graphie et à l'ordre des mots.
     */
    public function similarity(?string $a, ?string $b): float
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);

        if ($na === '' || $nb === '') {
            return 0.0;
        }
        if ($na === $nb) {
            return 1.0;
        }
        if ($this->key($a) === $this->key($b)) {
            return 0.97;
        }

        $sortedA = $this->tokens($a);
        sort($sortedA);
        $sortedB = $this->tokens($b);
        sort($sortedB);

        return max(
            JaroWinkler::similarity($na, $nb),
            JaroWinkler::similarity(implode(' ', $sortedA), implode(' ', $sortedB)),
            // Graphies proches après simplification phonétique.
            JaroWinkler::similarity((string) $this->key($a), (string) $this->key($b)) * 0.96,
        );
    }
}

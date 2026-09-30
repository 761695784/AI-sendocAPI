<?php

namespace App\Services\Assistant;

use App\Models\DocumentType;
use Illuminate\Support\Collection;

/**
 * Compréhension par règles : mots-clés français et wolof de config/assistant.php.
 * Volontairement simple et prévisible (pas de « boîte noire »).
 */
class Lexicon
{
    /** @var array<string, array<string, string[]>> */
    private array $intents;

    private ?Collection $types = null;

    public function __construct()
    {
        $this->intents = collect(config('assistant.intents', []))
            ->map(fn ($langs) => collect($langs)->map(fn ($words) => $this->prepare($words))->all())
            ->all();
    }

    /**
     * Intention la plus probable.
     *
     * @return array{intent: ?string, score: int, language: ?string}
     */
    public function detectIntent(string $text): array
    {
        $norm = TextNormalizer::normalize($text);
        $best = ['intent' => null, 'score' => 0, 'language' => null];

        foreach ($this->intents as $intent => $langs) {
            $score = 0;
            $byLang = [];
            foreach ($langs as $lang => $keywords) {
                $s = $this->score($norm, $keywords);
                $byLang[$lang] = $s;
                $score += $s;
            }
            if ($score > $best['score']) {
                arsort($byLang);
                $best = ['intent' => $intent, 'score' => $score, 'language' => array_key_first($byLang)];
            }
        }

        return $best;
    }

    /** Langue probable du message (null si rien de parlant). */
    public function detectLanguage(string $text): ?string
    {
        $norm = TextNormalizer::normalize($text);
        $totals = ['fr' => 0, 'wo' => 0];
        foreach ($this->intents as $langs) {
            foreach ($langs as $lang => $keywords) {
                $totals[$lang] = ($totals[$lang] ?? 0) + $this->score($norm, $keywords);
            }
        }
        foreach (['yes', 'no', 'skip'] as $group) {
            foreach ($this->prepare(config("assistant.{$group}", [])) as $kw) {
                if ($this->matches($norm, $kw)) {
                    $totals[$this->guessKeywordLanguage($kw)] += 1;
                }
            }
        }
        arsort($totals);
        $first = array_key_first($totals);

        return $totals[$first] > 0 && $totals[$first] > (array_values($totals)[1] ?? 0) ? $first : null;
    }

    /** Commande globale (cancel, restart, lang_wo, lang_fr) — message court uniquement. */
    public function command(string $text): ?string
    {
        $norm = TextNormalizer::normalize($text);
        if ($norm === '' || str_word_count($norm) > 4) {
            return null;
        }
        foreach (config('assistant.commands', []) as $command => $words) {
            foreach ($this->prepare($words) as $kw) {
                if ($this->matches($norm, $kw)) {
                    return $command;
                }
            }
        }

        return null;
    }

    public function isYes(string $text): bool
    {
        return $this->answer($text) === 'yes';
    }

    public function isNo(string $text): bool
    {
        return $this->answer($text) === 'no';
    }

    /** « je ne sais pas », « passer », « xamuma »… */
    public function isSkip(string $text): bool
    {
        $norm = TextNormalizer::normalize($text);
        foreach ($this->prepare(config('assistant.skip', [])) as $kw) {
            if ($this->matches($norm, $kw)) {
                return true;
            }
        }

        return false;
    }

    /** 'yes' | 'no' | null (le « non » l'emporte sur « oui … non »). */
    public function answer(string $text): ?string
    {
        $norm = TextNormalizer::normalize($text);
        if ($norm === '') {
            return null;
        }
        $hit = fn (string $group) => collect($this->prepare(config("assistant.{$group}", [])))->contains(fn ($kw) => $this->matches($norm, $kw));

        if ($hit('no') && ! str_contains($norm, 'je ne sais pas')) {
            return 'no';
        }

        return $hit('yes') ? 'yes' : null;
    }

    /** Type de pièce cité dans la phrase (l'expression la plus longue gagne). */
    public function documentType(string $text): ?DocumentType
    {
        $norm = TextNormalizer::normalize($text);
        $types = $this->documentTypes();

        // Réponse exacte (bouton) : nom du type.
        if ($exact = $types->first(fn (DocumentType $t) => TextNormalizer::normalize($t->TypeName) === $norm)) {
            return $exact;
        }

        $best = null;
        $bestLen = 0;
        foreach (config('assistant.document_types', []) as $typeName => $synonyms) {
            foreach ($this->prepare($synonyms) as $kw) {
                if (strlen($kw) > $bestLen && $this->matches($norm, $kw)) {
                    $type = $types->first(fn (DocumentType $t) => TextNormalizer::normalize($t->TypeName) === TextNormalizer::normalize($typeName));
                    if ($type) {
                        $best = $type;
                        $bestLen = strlen($kw);
                    }
                }
            }
        }

        return $best;
    }

    /**
     * « Je m'appelle Awa Ndiaye » → ['Awa', 'Ndiaye'] ; un seul mot → [mot, null].
     * Convention : le dernier mot est le nom de famille (corrigeable ensuite).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseFullName(string $text): array
    {
        $clean = $this->stripNamePrefix($text);
        $words = array_values(array_filter(preg_split('/\s+/u', $clean)));
        if (! $words) {
            return [null, null];
        }
        if (count($words) === 1) {
            return [self::titleCase($words[0]), null];
        }
        $last = array_pop($words);

        return [self::titleCase(implode(' ', $words)), self::titleCase($last)];
    }

    /** Un seul champ nom/prénom, préfixes retirés. */
    public function parseName(string $text): ?string
    {
        $clean = trim($this->stripNamePrefix($text));

        return $clean === '' ? null : self::titleCase($clean);
    }

    /** Numéro de pièce : lettres et chiffres uniquement (au moins 4 caractères, dont un chiffre). */
    public function docNumber(string $text): ?string
    {
        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $text));
        if (strlen($candidate) < 4 || ! preg_match('/\d/', $candidate) || strlen($candidate) > 30) {
            return null;
        }

        return $candidate;
    }

    public static function titleCase(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }

    // ── interne ──────────────────────────────────────────────────────

    private function stripNamePrefix(string $text): string
    {
        $value = trim(preg_replace('/[^\p{L}\p{M}\s\-\']/u', ' ', $text));
        $norm  = TextNormalizer::normalize($value);
        foreach ($this->prepare(config('assistant.name_prefixes', [])) as $prefix) {
            if ($norm === $prefix || str_starts_with($norm, $prefix . ' ')) {
                $count = count(explode(' ', $prefix));
                $words = preg_split('/[\s\']+/u', $value);
                // retire autant de « mots normalisés » que le préfixe en contient
                $removed = 0;
                while ($words && $removed < $count) {
                    $w = array_shift($words);
                    $removed += max(1, count(array_filter(explode(' ', TextNormalizer::normalize($w)))));
                }

                return trim(implode(' ', $words));
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function guessKeywordLanguage(string $kw): string
    {
        $wo = ['waaw', 'waw', 'wa', 'degg la', 'baax na', 'waaw degg la', 'deedeet', 'deet', 'dedet', 'dedeet', 'du degg', 'deet kay', 'xamuma', 'xamu ma', 'xamou ma'];

        return in_array($kw, $wo, true) ? 'wo' : 'fr';
    }

    private function score(string $norm, array $keywords): int
    {
        $score = 0;
        foreach ($keywords as $kw) {
            if ($this->matches($norm, $kw)) {
                $score += count(explode(' ', rtrim($kw, '*')));
            }
        }

        return $score;
    }

    /** Mot ou expression présent (limites de mots) ; « * » final = début de mot. */
    private function matches(string $norm, string $kw): bool
    {
        if ($norm === '' || $kw === '') {
            return false;
        }
        $prefix = str_ends_with($kw, '*');
        $core   = preg_quote(rtrim($kw, '*'), '/');
        $pattern = '/(^|\s)' . $core . ($prefix ? '[a-z0-9]*' : '') . '(\s|$)/';

        return (bool) preg_match($pattern, $norm);
    }

    /** @return string[] */
    private function prepare(array $words): array
    {
        return array_values(array_filter(array_map(function ($w) {
            $star = str_ends_with($w, '*');
            $n = TextNormalizer::normalize($w);

            return $n === '' ? '' : $n . ($star ? '*' : '');
        }, $words)));
    }

    private function documentTypes(): Collection
    {
        return $this->types ??= DocumentType::all();
    }
}

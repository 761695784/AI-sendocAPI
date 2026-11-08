<?php

namespace App\Services\Assistant;

/**
 * Savoir de l'assistant :
 *  - les fiches (config/assistant_faq.php) : réponses courtes données mot pour mot, recherche par mots-clés ;
 *  - la base de connaissances (resources/assistant/base-de-connaissances.md) : le texte complet sur DocTrack,
 *    seule source autorisée pour les réponses rédigées par l'IA. Une section = « ## Titre {#identifiant} ».
 */
class KnowledgeBase
{
    /** @var array<string, array{id: string, question: string, answer: string, links: array}>|null */
    private ?array $sections = null;

    private const STOPWORDS = ['comment', 'pourquoi', 'quoi', 'quel', 'quelle', 'quels', 'quelles', 'est', 'que', 'qui', 'les', 'des', 'une', 'pour',
        'dans', 'avec', 'sur', 'mon', 'mes', 'ma', 'ton', 'vos', 'votre', 'notre', 'faire', 'peut', 'peux', 'puis', 'faut', 'il', 'elle', 'je', 'j',
        'vous', 'nous', 'on', 'ce', 'cette', 'ces', 'son', 'sa', 'ses', 'pas', 'plus', 'tres', 'bien', 'aussi', 'alors', 'donc', 'mais', 'avoir', 'etre',
        'doctrack', 'document', 'documents', 'bonjour', 'svp', 'merci', 'aux', 'par', 'au', 'du', 'de', 'la', 'le', 'un', 'et', 'ou', 'a', 'en'];

    /** @return array<int, array{id: string, question: string, answer: string, keywords: string[], links?: array}> */
    public function entries(): array
    {
        return config('assistant_faq.entries', []);
    }

    public function find(string $id): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Sections de la base de connaissances, par identifiant.
     * Une ligne « Liens : [Libellé](/chemin), … » donne les boutons affichés sous la réponse.
     *
     * @return array<string, array{id: string, question: string, answer: string, links: array}>
     */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }
        $path = (string) config('assistant_faq.knowledge_file');
        $raw  = $path !== '' && is_file($path) ? (string) file_get_contents($path) : '';
        $raw  = preg_replace('/<!--.*?-->/s', '', str_replace("\r\n", "\n", $raw));

        $this->sections = [];
        $parts = preg_split('/^## +/m', $raw);
        array_shift($parts); // titre général et commentaires
        foreach ($parts as $part) {
            [$title, $body] = array_pad(explode("\n", $part, 2), 2, '');
            if (! preg_match('/^(.*?)\s*\{#([a-z0-9_]+)\}\s*$/', trim($title), $m)) {
                continue;
            }
            $links = [];
            $lines = [];
            foreach (explode("\n", trim($body)) as $line) {
                if (preg_match('/^Liens\s*:/i', $line)) {
                    // Chemins du site (/liste), WhatsApp (https://wa.me/…) et e-mail (mailto:…).
                    preg_match_all('/\[([^\]]+)\]\((\/[^)\s]*|https:\/\/wa\.me\/[^)\s]+|mailto:[^)\s]+)\)/', $line, $found, PREG_SET_ORDER);
                    foreach ($found as $f) {
                        $links[] = ['label' => $f[1], 'url' => $f[2]];
                    }
                    continue;
                }
                $lines[] = $line;
            }
            $this->sections[$m[2]] = ['id' => $m[2], 'question' => $m[1], 'answer' => trim(implode("\n", $lines)), 'links' => $links];
        }

        return $this->sections;
    }

    /**
     * Texte envoyé à l'IA : chaque section précédée de son identifiant.
     * Avec une question, seulement les sections les plus proches (+ « limites » et « contact ») :
     * moins de texte envoyé = réponses plus rapides et quota gratuit préservé.
     */
    public function document(?string $question = null, int $max = 0): string
    {
        $sections = $this->sections();
        if ($question !== null && $max > 0 && count($sections) > $max) {
            $sections = $this->relevantSections($question, $max);
        }

        return implode("\n\n", array_map(fn ($s) => "[{$s['id']}] {$s['question']}\n{$s['answer']}", $sections));
    }

    /**
     * Sections classées par mots en commun avec la question (racines de 5 lettres : « déclarer » ≈ « déclaration »).
     *
     * @return array<string, array>
     */
    public function relevantSections(string $question, int $max): array
    {
        $stems = self::stems(TextNormalizer::normalize($question));
        $scores = [];
        foreach ($this->sections() as $id => $s) {
            $title = self::stems(TextNormalizer::normalize($s['question']));
            $body  = self::stems(TextNormalizer::normalize($s['answer']));
            $scores[$id] = 2 * count(array_intersect($stems, $title)) + count(array_intersect($stems, $body));
        }
        arsort($scores);

        $keep = ['limites', 'contact'];
        $ids  = array_slice(array_keys(array_filter($scores)), 0, max(1, $max - count($keep)));
        $ids  = array_values(array_unique(array_merge($ids, $keep)));

        return array_intersect_key($this->sections(), array_flip($ids));
    }

    /** @return string[] */
    private static function stems(string $norm): array
    {
        return array_values(array_unique(array_map(
            fn ($w) => substr($w, 0, 5),
            self::significantWords($norm)
        )));
    }

    /** Source citable par l'IA : section de la base, ou fiche. */
    public function source(string $id): ?array
    {
        return $this->sections()[$id] ?? $this->find($id);
    }

    /**
     * Fiches classées par pertinence.
     *
     * @return array<int, array{entry: array, score: float}>
     */
    public function search(string $text, int $limit = 3): array
    {
        $norm  = TextNormalizer::normalize($text);
        $words = self::significantWords($norm);
        if ($norm === '') {
            return [];
        }

        $hits = [];
        foreach ($this->entries() as $entry) {
            $score = 0.0;
            foreach ($entry['keywords'] ?? [] as $kw) {
                if (self::matches($norm, $kw)) {
                    $score += count(explode(' ', rtrim($kw, '*')));
                }
            }
            // Mots importants en commun avec la question type de la fiche.
            $common = array_intersect($words, self::significantWords(TextNormalizer::normalize($entry['question'])));
            $score += 0.5 * count($common);

            if ($score > 0) {
                $hits[] = ['entry' => $entry, 'score' => $score];
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $limit);
    }

    /** Fiche à donner telle quelle : assez proche, et nettement devant la suivante. */
    public function bestMatch(string $text): ?array
    {
        $hits = $this->search($text, 2);
        if (! $hits || $hits[0]['score'] < (float) config('assistant_faq.min_score', 2)) {
            return null;
        }
        if (isset($hits[1]) && $hits[1]['score'] >= $hits[0]['score']) {
            return null; // ex aequo : on laisse l'IA (ou le menu) départager
        }

        return $hits[0];
    }

    /** @return string[] */
    private static function significantWords(string $norm): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $norm),
            fn ($w) => strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true)
        )));
    }

    private static function matches(string $norm, string $kw): bool
    {
        $prefix = str_ends_with($kw, '*');
        $core   = preg_quote(rtrim($kw, '*'), '/');

        return (bool) preg_match('/(^|\s)' . $core . ($prefix ? '[a-z0-9]*' : '') . '(\s|$)/', $norm);
    }
}

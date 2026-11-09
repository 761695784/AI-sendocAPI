<?php

namespace App\Services\Reports;

use App\Models\ActivityReport;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\ChatClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Rédaction assistée d'un rapport d'activité par un modèle de langage.
 *
 * Trois garde-fous :
 *  1. Le modèle ne reçoit QUE des chiffres agrégés (aucun nom de personne,
 *     numéro, téléphone ; petits effectifs masqués en « <3 »).
 *  2. Chaque nombre du texte produit doit figurer dans ces données : sinon
 *     nouvelle tentative, puis fournisseur suivant, puis rien (analyse par règles).
 *  3. Le texte n'est publié qu'après validation humaine (réglable).
 */
class ReportNarrator
{
    public function __construct(private ChatClient $chat) {}

    public function enabled(): bool
    {
        return (bool) config('ai.reports.enabled')
            && collect(config('ai.reports.providers', []))->contains(fn ($p) => $this->chat->configured($p));
    }

    /**
     * @return array{narrative: ?array, attempts: array}
     */
    public function write(ActivityReport $report): array
    {
        $payload = $this->payload($report->data ?? [], $report->analysis ?? []);
        $allowed = $this->allowedNumbers($payload);
        $names   = array_column($payload['structures'] ?? [], 'name');
        $attempts = [];

        foreach (config('ai.reports.providers', []) as $provider) {
            if (! $this->chat->configured($provider)) {
                continue;
            }
            $messages = $this->messages($payload);

            for ($try = 1; $try <= (int) config('ai.reports.max_attempts', 2); $try++) {
                try {
                    $answer = $this->chat->complete($provider, $messages,
                        (float) config('ai.reports.temperature', 0.2),
                        (int) config('ai.reports.max_tokens', 2500),
                        (int) config('ai.reports.timeout', 90),
                        // Longue attente seulement hors requête web (rapport mensuel planifié, commande) :
                        // un clic sur « Régénérer » ne doit pas dépasser le délai d'exécution du serveur.
                        app()->runningInConsole() ? (float) config('ai.reports.retry_after_max', 30) : null);
                } catch (AiUnavailableException $e) {
                    $attempts[] = ['provider' => $provider, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 400)];
                    Log::warning('Rédaction IA du rapport impossible : ' . $e->getMessage());
                    continue 2;   // fournisseur suivant
                }

                $narrative = $this->parse($answer['text'], $names);
                if (! $narrative) {
                    $attempts[] = ['provider' => $provider, 'model' => $answer['model'], 'ok' => false, 'error' => 'réponse illisible (JSON attendu)'];
                    $messages[] = ['role' => 'assistant', 'content' => mb_substr($answer['text'], 0, 4000)];
                    $messages[] = ['role' => 'user', 'content' => 'Réponds uniquement avec l\'objet JSON demandé, sans texte autour.'];
                    continue;
                }

                $unknown = $this->unverifiedNumbers($narrative, $allowed);
                if ($unknown) {
                    $attempts[] = ['provider' => $provider, 'model' => $answer['model'], 'ok' => false, 'error' => 'chiffres absents des données : ' . implode(', ', array_slice($unknown, 0, 10))];
                    $messages[] = ['role' => 'assistant', 'content' => mb_substr($answer['text'], 0, 4000)];
                    $messages[] = ['role' => 'user', 'content' => 'Ces nombres ne figurent pas dans les données : ' . implode(', ', $unknown)
                        . '. Réécris le JSON en n\'utilisant QUE des nombres présents tels quels dans les données (aucun calcul, aucune estimation).'];
                    continue;
                }

                $attempts[] = ['provider' => $provider, 'model' => $answer['model'], 'ok' => true];

                return ['narrative' => $narrative + [
                    'provider'     => $provider,
                    'model'        => $answer['model'],
                    'verified'     => true,
                    'generated_at' => now()->toIso8601String(),
                ], 'attempts' => $attempts];
            }
        }

        return ['narrative' => null, 'attempts' => $attempts];
    }

    // ── Données envoyées : agrégats uniquement ───────────────────────

    public function payload(array $d, array $a): array
    {
        $min = (int) config('ai.reports.min_count', 3);
        $mask = function ($value) use ($min) {
            return is_int($value) && $value > 0 && $value < $min ? "<{$min}" : $value;
        };
        $maskCounts = fn (array $row, array $keep = []) => collect($row)
            ->map(fn ($v, $k) => in_array($k, $keep, true) || str_contains((string) $k, 'pct') || str_contains((string) $k, 'hours') || str_contains((string) $k, 'days') ? $v : $mask($v))
            ->all();

        // Structures : seules les plus actives sont détaillées (un rapport national peut en compter des centaines).
        $all = collect($d['structures'] ?? []);
        $activity = fn (array $s) => collect(['collections_assigned', 'collected', 'collections_overdue_now', 'in_custody_now', 'pending_reviews_now', 'counter_declarations', 'restitutions'])
            ->sum(fn ($k) => is_numeric($s[$k] ?? null) ? (int) $s[$k] : 0);
        $active = $all->filter(fn ($s) => $activity((array) $s) > 0)
            ->sortBy([fn ($x, $y) => ((int) ($y['collections_overdue_now'] ?? 0)) <=> ((int) ($x['collections_overdue_now'] ?? 0)), fn ($x, $y) => $activity((array) $y) <=> $activity((array) $x)])
            ->values();
        $detail = fn (int $n) => $active->take($n)->map(fn ($s) => collect($maskCounts(Arr::except((array) $s, ['uuid']), ['name', 'parent', 'is_active']))
            ->reject(fn ($v, $k) => $v === null || $v === 0 || ($k === 'is_active' && $v === true))->all())->values()->all();

        // Évolution : { période: valeur } au lieu d'une liste d'objets (moins de texte).
        $evolution = $d['evolution'] ?? [];
        if (isset($evolution['series']) && is_array($evolution['series'])) {
            $evolution['series'] = collect($evolution['series'])
                ->map(fn ($points) => collect($points)->mapWithKeys(fn ($p) => [$p['period'] => $p['value']])->all())->all();
        }

        $build = fn (int $nStructures, bool $withEvolution, int $nRows) => self::withoutNulls([
            'rapport'   => [
                'titre'     => $d['meta']['title'] ?? null,
                'perimetre' => $d['meta']['scope']['name'] ?? null,
                'periode'   => $d['meta']['period']['label'] ?? null,
                'delais_cibles_heures' => $d['meta']['sla'] ?? null,
                'facturation' => ($d['meta']['billing_mode'] ?? 'pilot') === 'live' ? 'payante' : 'phase pilote gratuite',
            ],
            'vue_ensemble'      => $d['overview'] ?? [],
            'comparaison_periode_precedente' => $d['comparison'] ?? [],
            'structures_resume' => [
                'total'                => $all->count(),
                'avec_activite'        => $active->count(),
                'sans_activite'        => $all->count() - $active->count(),
                'detaillees_ci_dessous' => min($nStructures, $active->count()),
            ],
            'structures'        => $detail($nStructures),
            'evolution'         => $withEvolution ? $evolution : null,
            'par_type_de_piece' => collect($d['by_document_type'] ?? [])->take($nRows)->map(fn ($r) => $maskCounts((array) $r, ['name', 'type']))->values()->all(),
            'par_region'        => collect($d['by_region'] ?? [])->take($nRows)->map(fn ($r) => $maskCounts((array) $r, ['name', 'region']))->values()->all(),
            'restitutions_directes' => $d['direct_restitutions'] ?? [],
            'qr_codes'          => $d['qr'] ?? [],
            'finances'          => Arr::except($d['billing'] ?? [], ['charges']),
            'collectes_en_retard' => count($d['overdue_collections'] ?? []),
            'analyse_par_regles' => [
                'score'           => $a['score'] ?? null,
                'niveau'          => $a['level'] ?? null,
                'alertes'         => array_column($a['alerts'] ?? [], 'message'),
                'points_positifs' => $a['positives'] ?? [],
            ],
        ]);

        // Taille limitée (quota par minute des formules gratuites) : on réduit le détail jusqu'à tenir.
        $budget = (int) config('ai.reports.max_input_chars', 10000);
        $n = (int) config('ai.reports.max_structures', 12);
        foreach ([[$n, true, 15], [(int) ceil($n / 2), true, 10], [(int) ceil($n / 2), false, 8], [3, false, 5], [0, false, 5]] as [$k, $evo, $rows]) {
            $payload = $build($k, $evo, $rows);
            if (mb_strlen(self::compactJson($payload)) <= $budget) {
                break;
            }
        }

        return $payload;
    }

    /** Retire les valeurs nulles et les tableaux vides (moins de texte, rien d'inventé). */
    private static function withoutNulls(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = self::withoutNulls($v);
                if ($v === [] ) {
                    continue;
                }
            }
            if ($v !== null) {
                $out[$k] = $v;
            }
        }

        return array_is_list($data) ? array_values($out) : $out;
    }

    private function messages(array $payload): array
    {
        $system = <<<'TXT'
Tu es l'analyste de DocTrack, la plateforme sénégalaise de déclaration des pièces perdues et de restitution des documents trouvés,
utilisée par la Police et la Gendarmerie. Tu rédiges la partie commentée d'un rapport d'activité destiné à des directeurs.

Règles impératives :
- Utilise UNIQUEMENT les nombres présents dans les données, recopiés tels quels. Ne calcule rien, n'estime rien, n'arrondis pas autrement.
  Si une information manque ou vaut null, dis-le simplement sans chiffre.
- « <3 » signifie « moins de 3 » : ne le remplace jamais par un nombre.
- Ton sobre, factuel, en français administratif clair ; pas de jargon technique ; pas d'emphase.
- Ne cite aucune personne. Les structures (commissariats, brigades) peuvent être nommées.
- « structures » ne détaille que les structures les plus actives (voir « structures_resume ») ; un indicateur absent d'une structure vaut 0.
  Ne commente que les structures détaillées.
- Les recommandations doivent être concrètes, réalisables par les structures, et découler des chiffres.

Réponds UNIQUEMENT avec un objet JSON de cette forme :
{
  "summary_fr": "synthèse de 120 à 200 mots",
  "highlights": ["3 à 5 constats clés, une phrase chacun"],
  "structures": [{"name": "nom exact d'une structure des données", "comment": "une ou deux phrases"}],
  "recommendations": [{"priority": "haute|moyenne|basse", "text": "une action concrète"}],
  "summary_en": "the same summary in English, 100 to 170 words"
}
TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Données agrégées du rapport (JSON) :\n" . self::compactJson($payload)],
        ];
    }

    /** JSON sans mise en forme : moins de jetons envoyés (quota par minute des formules gratuites). */
    public static function compactJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Le message envoyé au modèle (pour le diagnostic : taille, contenu). */
    public function preview(ActivityReport $report): array
    {
        return $this->messages($this->payload($report->data ?? [], $report->analysis ?? []));
    }

    // ── Lecture et vérification ──────────────────────────────────────

    public function parse(string $text, array $structureNames): ?array
    {
        $json = preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;
        if (! is_array($json) || empty($json['summary_fr']) || ! is_string($json['summary_fr'])) {
            return null;
        }

        // Retire les vraies balises HTML seulement : strip_tags() effaçait tout ce qui suit « <3 » (« moins de 3 »).
        $clean = fn ($s) => self::clean((string) $s);
        $known = collect($structureNames)->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => $n]);

        return [
            'summary_fr'      => $clean($json['summary_fr']),
            'summary_en'      => isset($json['summary_en']) ? $clean($json['summary_en']) : null,
            'highlights'      => collect($json['highlights'] ?? [])->filter(fn ($h) => is_string($h) && trim($h) !== '')->map($clean)->take(6)->values()->all(),
            'structures'      => collect($json['structures'] ?? [])
                ->filter(fn ($s) => is_array($s) && isset($s['name'], $s['comment']) && $known->has(mb_strtolower(trim($s['name']))))
                ->map(fn ($s) => ['name' => $known[mb_strtolower(trim($s['name']))], 'comment' => $clean($s['comment'])])
                ->values()->all(),
            'recommendations' => collect($json['recommendations'] ?? [])
                ->filter(fn ($r) => is_array($r) && ! empty($r['text']))
                ->map(fn ($r) => [
                    'priority' => in_array($r['priority'] ?? null, ['haute', 'moyenne', 'basse'], true) ? $r['priority'] : 'moyenne',
                    'text'     => $clean($r['text']),
                ])->take(8)->values()->all(),
        ];
    }

    /** Texte du modèle sans balises HTML, en gardant « <3 », « < 24 h »… (l'affichage échappe le texte). */
    public static function clean(string $text): string
    {
        $text = preg_replace('/<\/?[a-zA-Z][^<>]*>|<!--.*?-->/s', '', $text);

        return trim(preg_replace('/[ \t]+/', ' ', $text));
    }

    /** Nombres présents dans les données (et leurs arrondis usuels). */
    public function allowedNumbers(array $payload): array
    {
        $allowed = [];
        // Nombres écrits dans les noms des indicateurs (ex. « moins_de_30_jours »).
        $keys = function (array $a) use (&$keys, &$allowed) {
            foreach ($a as $k => $v) {
                if (is_string($k)) {
                    foreach (self::numbersIn(str_replace('_', ' ', $k)) as $n) {
                        $allowed[self::key($n)] = true;
                    }
                }
                if (is_array($v)) {
                    $keys($v);
                }
            }
        };
        $keys($payload);
        array_walk_recursive($payload, function ($value) use (&$allowed) {
            if (is_int($value) || is_float($value)) {
                foreach ([$value, abs($value), round($value), round($value, 1), round(abs($value)), round(abs($value), 1)] as $v) {
                    $allowed[self::key($v)] = true;
                }
            } elseif (is_string($value)) {
                // Nombres écrits dans les textes (libellés de période, alertes de l'analyse par règles).
                foreach (self::numbersIn($value) as $n) {
                    $allowed[self::key($n)] = true;
                }
            }
        });
        // Petits entiers (« deux structures », « 3 constats »), toujours admis.
        foreach (range(0, 10) as $n) {
            $allowed[self::key($n)] = true;
        }

        return $allowed;
    }

    /** @return string[] nombres du texte absents des données */
    public function unverifiedNumbers(array $narrative, array $allowed): array
    {
        $texts = [$narrative['summary_fr'] ?? '', $narrative['summary_en'] ?? ''];
        foreach (['highlights'] as $k) {
            $texts = array_merge($texts, $narrative[$k] ?? []);
        }
        foreach ($narrative['structures'] ?? [] as $s) {
            $texts[] = $s['comment'];
        }
        foreach ($narrative['recommendations'] ?? [] as $r) {
            $texts[] = $r['text'];
        }

        $bad = [];
        foreach ($texts as $text) {
            foreach (self::numbersIn((string) $text) as $n) {
                if (! isset($allowed[self::key($n)])) {
                    $bad[] = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
                }
            }
        }

        return array_values(array_unique($bad));
    }

    /** @return float[] */
    public static function numbersIn(string $text): array
    {
        // « <3 » n'est pas un chiffre avancé par le modèle ; « 1 234 » = 1234 ; « 12,5 » = 12.5
        $text = preg_replace('/<\s*\d+/', ' ', $text);
        $text = preg_replace('/(\d)[\x{00A0}\x{202F} ](?=\d{3}\b)/u', '$1', $text);
        preg_match_all('/(?<![\w.,])\d+(?:[.,]\d+)?/u', $text, $m);

        return array_map(fn ($n) => (float) str_replace(',', '.', $n), $m[0]);
    }

    private static function key(float|int $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }
}

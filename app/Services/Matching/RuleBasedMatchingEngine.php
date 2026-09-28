<?php

namespace App\Services\Matching;

use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\Territory;
use Carbon\CarbonInterface;

/**
 * Moteur de correspondance à règles, pondéré et explicable.
 *
 * Critères (poids dans config/matching.php) :
 *  - noms (prénom + nom, ordre inversé toléré, fautes, graphies sénégalaises)
 *  - date de naissance
 *  - cohérence temporelle (trouvé après la perte)
 *  - lieu (même région)
 * Le numéro de document, quand il est connu des deux côtés, est décisif :
 * identique → correspondance quasi certaine ; différent → score plafonné.
 * Un critère inconnu compte pour moitié (ni preuve, ni contre-preuve).
 */
class RuleBasedMatchingEngine implements MatchingEngine
{
    public function __construct(private NameNormalizer $names) {}

    public function name(): string
    {
        return 'rules';
    }

    public function version(): string
    {
        return config('matching.version', 'rules-1.0');
    }

    public function score(DeclarationItem $item, Document $document): MatchResult
    {
        $weights = config('matching.weights');
        $details = [];

        // ── Noms ────────────────────────────────────────────────
        [$nameScore, $nameDetail] = $this->compareNames(
            $item->first_name, $item->last_name,
            $document->OwnerFirstName, $document->OwnerLastName,
        );
        $details['names'] = $nameDetail + ['points' => round($nameScore * $weights['names'], 1), 'max' => $weights['names']];

        // ── Date de naissance ───────────────────────────────────
        $birthFactor = 0.5;
        $birthNote   = 'inconnue d\'un côté';
        $birthMismatch = false;
        if ($item->birth_date_in_doc && $document->birth_date_in_doc) {
            $same = $item->birth_date_in_doc->isSameDay($document->birth_date_in_doc);
            $birthFactor   = $same ? 1.0 : 0.0;
            $birthMismatch = ! $same;
            $birthNote     = $same ? 'identique' : 'différente';
        }
        $details['birth_date'] = ['result' => $birthNote, 'points' => round($birthFactor * $weights['birth_date'], 1), 'max' => $weights['birth_date']];

        // ── Cohérence temporelle ────────────────────────────────
        $temporalFactor = 0.5;
        $temporalNote   = 'date de perte inconnue';
        $lostAt  = $item->declaration?->lost_at;
        $foundAt = $document->found_at ?? $document->created_at;
        if ($lostAt instanceof CarbonInterface && $foundAt instanceof CarbonInterface) {
            $ok = $foundAt->copy()->startOfDay()->gte($lostAt->copy()->subDays(config('matching.temporal_tolerance_days', 3)));
            $temporalFactor = $ok ? 1.0 : 0.0;
            $temporalNote   = $ok ? 'trouvé après la perte' : 'trouvé AVANT la perte déclarée';
        }
        $details['temporal'] = ['result' => $temporalNote, 'points' => round($temporalFactor * $weights['temporal'], 1), 'max' => $weights['temporal']];

        // ── Lieu (région) ───────────────────────────────────────
        $locationFactor = 0.5;
        $locationNote   = 'lieu inconnu d\'un côté';
        $regionA = $this->regionId($item->declaration?->territory_id);
        $regionB = $this->regionId($document->territory_id);
        if ($regionA && $regionB) {
            $locationFactor = $regionA === $regionB ? 1.0 : 0.3;
            $locationNote   = $regionA === $regionB ? 'même région' : 'régions différentes';
        }
        $details['location'] = ['result' => $locationNote, 'points' => round($locationFactor * $weights['location'], 1), 'max' => $weights['location']];

        $score = $nameScore * $weights['names']
            + $birthFactor * $weights['birth_date']
            + $temporalFactor * $weights['temporal']
            + $locationFactor * $weights['location'];

        // ── Numéro de document ─────────────────────────────────
        $numberMatch = false;
        if ($item->doc_number_hash && $document->doc_number_hash) {
            if (hash_equals($item->doc_number_hash, $document->doc_number_hash)) {
                $numberMatch = true;
                $score = max($score, 95);
                $details['document_number'] = ['result' => 'identique'];
            } else {
                $score = min($score, config('matching.number_mismatch_cap', 30));
                $details['document_number'] = ['result' => 'différent (score plafonné)'];
            }
        } else {
            $details['document_number'] = ['result' => 'inconnu d\'un côté'];
        }

        if ($birthMismatch && ! $numberMatch) {
            $score = min($score, config('matching.birth_date_mismatch_cap', 45));
        }

        return new MatchResult((int) round(min($score, 100)), $numberMatch, $nameScore, $details);
    }

    /**
     * Meilleure comparaison entre l'ordre normal et l'ordre inversé
     * (prénom/nom saisis à l'envers : fréquent sur les pièces et au guichet).
     * Un nom très différent fait chuter le score (évite les homonymes partiels :
     * frère et sœur, même nom de famille).
     */
    private function compareNames(?string $firstA, ?string $lastA, ?string $firstB, ?string $lastB): array
    {
        $direct = [
            'first' => $this->names->similarity($firstA, $firstB),
            'last'  => $this->names->similarity($lastA, $lastB),
        ];
        $swapped = [
            'first' => $this->names->similarity($firstA, $lastB),
            'last'  => $this->names->similarity($lastA, $firstB),
        ];

        $combine = function (array $s): float {
            $min  = min($s['first'], $s['last']);
            $mean = ($s['first'] + $s['last']) / 2;

            return $min >= 0.85 ? $mean : $mean * ($min / 0.85);
        };

        $directScore  = $combine($direct);
        $swappedScore = $combine($swapped);
        $useSwapped   = $swappedScore > $directScore;
        $best         = $useSwapped ? $swapped : $direct;

        return [max($directScore, $swappedScore), [
            'first_name' => round($best['first'], 2),
            'last_name'  => round($best['last'], 2),
            'swapped'    => $useSwapped,
        ]];
    }

    private function regionId(?int $territoryId): ?int
    {
        if (! $territoryId) {
            return null;
        }
        static $cache = [];

        return $cache[$territoryId] ??= (function () use ($territoryId) {
            $t = Territory::find($territoryId);

            return $t ? ($t->level === Territory::LEVEL_REGION ? $t->id : $t->parent_id) : null;
        })();
    }
}

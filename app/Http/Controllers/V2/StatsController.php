<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Services\Stats\StatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Statistiques par périmètre.
 * Paramètres communs : organization (uuid, facultatif), from, to (dates, défaut : 30 derniers jours).
 */
class StatsController extends Controller
{
    public function __construct(private StatsService $stats) {}

    public function overview(Request $request)
    {
        [$scope, $from, $to] = $this->context($request);

        return response()->json([
            'scope'  => $this->scopeInfo($scope),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ] + $this->stats->overview($scope['ids'], $from, $to));
    }

    /** Comparaison des structures (JSON, ou CSV avec ?format=csv). */
    public function structures(Request $request)
    {
        [$scope, $from, $to] = $this->context($request);
        $rows = $this->stats->structures($scope['ids'], $from, $to);

        if ($request->query('format') === 'csv') {
            return $this->csv($rows->all(), 'doctrack-structures-' . $from->toDateString() . '-' . $to->toDateString() . '.csv');
        }

        return response()->json([
            'scope'  => $this->scopeInfo($scope),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sla'    => config('doctrack.sla'),
            'data'   => $rows,
        ]);
    }

    public function timeseries(Request $request)
    {
        $request->validate([
            'metric'   => 'required|in:counter_declarations,deposits,collections_assigned,collected,matches_confirmed,restitutions',
            'interval' => 'nullable|in:day,week,month',
        ]);
        [$scope, $from, $to] = $this->context($request);

        return response()->json([
            'scope'    => $this->scopeInfo($scope),
            'metric'   => $request->query('metric'),
            'interval' => $request->query('interval', 'week'),
            'points'   => $this->stats->timeseries($scope['ids'], $request->query('metric'), $request->query('interval', 'week'), $from, $to),
        ]);
    }

    public function overdueCollections(Request $request)
    {
        [$scope] = $this->context($request);

        return response()->json([
            'scope'               => $this->scopeInfo($scope),
            'first_contact_hours' => config('doctrack.sla.first_contact_hours'),
            'data'                => $this->stats->overdueCollections($scope['ids']),
        ]);
    }

    private function context(Request $request): array
    {
        $request->validate([
            'organization' => 'nullable|uuid',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
        ]);

        $scope = $this->stats->resolveScope($request->user(), $request->query('organization'));
        $to    = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now();
        $from  = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->subDays(30)->startOfDay();
        abort_if($from->diffInDays($to) > 731, 422, 'Période limitée à 2 ans.');

        return [$scope, $from, $to];
    }

    private function scopeInfo(array $scope): array
    {
        $org = $scope['organization'];

        return $org
            ? ['level' => $org->type?->level, 'organization' => ['uuid' => $org->uuid, 'name' => $org->name], 'structures' => count($scope['ids'])]
            : ['level' => 'national', 'organization' => null];
    }

    private function csv(array $rows, string $filename)
    {
        $headers = [
            'uuid' => 'Identifiant', 'name' => 'Structure', 'parent' => 'Rattachement', 'is_active' => 'Active',
            'collections_assigned' => 'Collectes reçues', 'median_first_contact_hours' => '1er appel (h, médiane)',
            'contacted_within_sla_pct' => 'Appelés dans le délai (%)', 'collections_overdue_now' => 'Collectes en retard',
            'collected' => 'Documents récupérés', 'median_hours_to_collect' => 'Délai de récupération (h, médiane)',
            'in_custody_now' => 'Sous garde', 'pending_reviews_now' => 'Correspondances à vérifier',
            'counter_declarations' => 'Déclarations au guichet', 'restitutions' => 'Restitutions',
        ];

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            fputcsv($out, array_values($headers), ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($k) => is_bool($row[$k]) ? ($row[$k] ? 'oui' : 'non') : $row[$k], array_keys($headers)), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

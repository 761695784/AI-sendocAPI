<?php

namespace App\Services\Reports;

use App\Models\CollectionRequest;
use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\Organization;
use App\Models\RestitutionRequest;
use App\Models\Restitution;
use App\Models\TagScan;
use App\Models\Territory;
use App\Services\Billing\BillingReport;
use App\Services\Stats\StatsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Rassemble toutes les données d'un rapport V2 pour un périmètre et une période :
 * synthèse, comparaison avec la période précédente, structures, évolution,
 * types de documents, régions, restitutions directes, QR, finances, retards.
 */
class ReportBuilder
{
    public function __construct(private StatsService $stats, private BillingReport $billing) {}

    public function build(?Organization $scope, ?array $ids, CarbonInterface $from, CarbonInterface $to): array
    {
        $days    = max(1, (int) ceil($from->diffInDays($to)));
        $prevTo  = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($days)->startOfDay();
        $interval = $days <= 14 ? 'day' : ($days <= 124 ? 'week' : 'month'); // lisible en une page

        $overview = $this->stats->overview($ids, $from, $to);
        $previous = $this->stats->overview($ids, $prevFrom, $prevTo);

        return [
            'meta' => [
                'title'        => 'Rapport d\'activité DocTrack — ' . ($scope?->name ?? 'National'),
                'scope'        => $scope ? ['uuid' => $scope->uuid, 'name' => $scope->name, 'level' => $scope->type?->level] : ['name' => 'National', 'level' => 'national'],
                'period'       => [
                    'from'  => $from->toDateString(),
                    'to'    => $to->toDateString(),
                    'label' => self::periodLabel($from, $to),
                ],
                'previous'     => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
                'generated_at' => now()->toIso8601String(),
                'billing_mode' => config('billing.mode'),
                'sla'          => config('doctrack.sla'),
            ],
            'overview'      => $overview,
            'comparison'    => $this->compare($overview, $previous),
            'structures'    => $this->stats->structures($ids, $from, $to)->all(),
            'evolution'     => [
                'interval' => $interval,
                'series'   => collect(['counter_declarations', 'deposits', 'collections_assigned', 'collected', 'restitutions'])
                    ->mapWithKeys(fn ($m) => [$m => $this->stats->timeseries($ids, $m, $interval, $from, $to)])->all(),
            ],
            'by_document_type'     => $this->byDocumentType($ids, $from, $to),
            'by_region'            => $this->byRegion($ids, $from, $to),
            'direct_restitutions'  => $ids === null ? $this->directRestitutions($from, $to) : null,
            'qr'                   => $ids === null ? $this->qr($from, $to) : null,
            'billing'              => $this->billing->build($ids, $from, $to),
            'overdue_collections'  => $this->stats->overdueCollections($ids)->take(15)->values()->all(),
        ];
    }

    public static function periodLabel(CarbonInterface $from, CarbonInterface $to): string
    {
        $f = Carbon::parse($from)->locale('fr');
        $t = Carbon::parse($to)->locale('fr');

        if ($f->isSameDay($f->copy()->startOfMonth()) && $t->isSameDay($f->copy()->endOfMonth())) {
            return ucfirst($f->translatedFormat('F Y'));
        }
        if ($f->isSameDay($f->copy()->startOfYear()) && $t->isSameDay($f->copy()->endOfYear())) {
            return 'Année ' . $f->year;
        }

        return 'Du ' . $f->format('d/m/Y') . ' au ' . $t->format('d/m/Y');
    }

    /** Variation des indicateurs clés par rapport à la période précédente. */
    private function compare(array $now, array $before): array
    {
        $pick = fn ($o) => [
            'counter_declarations'     => $o['declarations']['counter'],
            'deposited_at_counter'     => $o['found_documents']['deposited_at_counter'],
            'collected_from_finders'   => $o['found_documents']['collected_from_finders'],
            'matches_created'          => $o['matches']['created'],
            'collections_assigned'     => $o['collections']['assigned'],
            'restitutions'             => $o['restitutions']['completed'],
            'median_first_contact_hours' => $o['collections']['median_first_contact_hours'],
        ];
        $a = $pick($now);
        $b = $pick($before);

        return collect($a)->map(fn ($v, $k) => [
            'current'    => $v,
            'previous'   => $b[$k],
            'change_pct' => ($v !== null && $b[$k]) ? round(($v - $b[$k]) / $b[$k] * 100, 1) : null,
        ])->all();
    }

    private function byDocumentType(?array $ids, CarbonInterface $from, CarbonInterface $to): array
    {
        $declared = DeclarationItem::query()
            ->join('declaration_de_pertes', 'declaration_de_pertes.id', '=', 'declaration_items.declaration_de_perte_id')
            ->join('document_types', 'document_types.id', '=', 'declaration_items.document_type_id')
            ->whereBetween('declaration_de_pertes.created_at', [$from, $to])
            ->when($ids !== null, fn ($q) => $q->whereIn('declaration_de_pertes.organization_id', $ids ?: [0]))
            ->selectRaw('document_types.TypeName as type, count(*) as n')->groupBy('document_types.TypeName')->pluck('n', 'type');

        $found = Document::query()->join('document_types', 'document_types.id', '=', 'documents.document_type_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('documents.custody_organization_id', $ids ?: [0]))
            ->whereBetween('documents.created_at', [$from, $to])
            ->selectRaw('document_types.TypeName as type, count(*) as n')->groupBy('document_types.TypeName')->pluck('n', 'type');

        $restituted = Restitution::query()->where('restitutions.status', Restitution::STATUS_COMPLETED)
            ->join('documents', 'documents.id', '=', 'restitutions.document_id')
            ->join('document_types', 'document_types.id', '=', 'documents.document_type_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('restitutions.organization_id', $ids ?: [0]))
            ->whereBetween('restitutions.handed_over_at', [$from, $to])
            ->selectRaw('document_types.TypeName as type, count(*) as n')->groupBy('document_types.TypeName')->pluck('n', 'type');

        return collect($declared->keys())->merge($found->keys())->merge($restituted->keys())->unique()->sort()->values()
            ->map(fn ($type) => [
                'type'       => $type,
                'declared'   => (int) ($declared[$type] ?? 0),
                'found'      => (int) ($found[$type] ?? 0),
                'restituted' => (int) ($restituted[$type] ?? 0),
            ])->sortByDesc('declared')->values()->all();
    }

    private function byRegion(?array $ids, CarbonInterface $from, CarbonInterface $to): array
    {
        $territories = Territory::all(['id', 'name', 'level', 'parent_id'])->keyBy('id');
        $regionOf = function (?int $id) use ($territories) {
            $t = $id ? $territories->get($id) : null;
            while ($t && $t->level !== Territory::LEVEL_REGION) {
                $t = $t->parent_id ? $territories->get($t->parent_id) : null;
            }

            return $t?->name ?? 'Non renseignée';
        };

        $declarations = DeclarationDePerte::query()->where('channel', DeclarationDePerte::CHANNEL_COUNTER)
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids ?: [0]))
            ->whereBetween('created_at', [$from, $to])->pluck('territory_id')->countBy(fn ($id) => $regionOf($id));

        $found = Document::query()->when($ids !== null, fn ($q) => $q->whereIn('custody_organization_id', $ids ?: [0]))
            ->whereBetween('created_at', [$from, $to])->pluck('territory_id')->countBy(fn ($id) => $regionOf($id));

        $restitutions = Restitution::query()->where('status', Restitution::STATUS_COMPLETED)
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids ?: [0]))
            ->whereBetween('handed_over_at', [$from, $to])
            ->with('organization:id,territory_id')->get()->countBy(fn ($r) => $regionOf($r->organization?->territory_id));

        return collect($declarations->keys())->merge($found->keys())->merge($restitutions->keys())->unique()
            ->map(fn ($region) => [
                'region'       => $region,
                'declarations' => (int) ($declarations[$region] ?? 0),
                'found'        => (int) ($found[$region] ?? 0),
                'restitutions' => (int) ($restitutions[$region] ?? 0),
            ])->sortByDesc('declarations')->values()->all();
    }

    private function directRestitutions(CarbonInterface $from, CarbonInterface $to): array
    {
        $byStatus = RestitutionRequest::whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $total = $byStatus->sum();

        return [
            'requests'        => $total,
            'by_status'       => $byStatus->all(),
            'acceptance_pct'  => $total ? round((($byStatus['accepted'] ?? 0) + ($byStatus['completed'] ?? 0)) / $total * 100, 1) : null,
        ];
    }

    private function qr(CarbonInterface $from, CarbonInterface $to): array
    {
        $scans = TagScan::whereBetween('created_at', [$from, $to]);

        return [
            'tags_created'          => DocumentTag::whereBetween('created_at', [$from, $to])->count(),
            'tags_active_total'     => DocumentTag::where('status', DocumentTag::STATUS_ACTIVE)->count(),
            'scans'                 => (clone $scans)->count(),
            'scans_with_collection' => (clone $scans)->where('contact_consent', true)->count(),
            'collections_from_qr'   => CollectionRequest::whereBetween('created_at', [$from, $to])
                ->whereHas('document', fn ($q) => $q->where('found_channel', Document::CHANNEL_QR_SCAN))->count(),
        ];
    }
}

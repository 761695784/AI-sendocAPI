<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\ActivityReport;
use App\Models\Organization;
use App\Services\Access\OrganizationAccess;
use App\Services\Reports\ActivityReportService;
use App\Services\Stats\StatsService;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rapports d'activité V2 (PDF + Excel) par périmètre.
 * Droit requis : stats.view sur le périmètre (super admin : national et tout).
 */
class ActivityReportController extends Controller
{
    public function __construct(
        private ActivityReportService $reports,
        private StatsService $stats,
        private OrganizationAccess $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $page = ActivityReport::with('organization:id,uuid,name')
            ->when(! $user->isSuperAdmin(), function ($q) use ($user) {
                $ids = $this->access->organizationIdsWithAbility($user, 'stats.view');
                abort_if(empty($ids), 403);
                $q->whereIn('organization_id', $ids);
            })
            ->when($request->query('organization'), fn ($q, $uuid) => $q->whereHas('organization', fn ($o) => $o->where('uuid', $uuid)))
            ->when($request->query('national') && $user->isSuperAdmin(), fn ($q) => $q->whereNull('organization_id'))
            ->latest('period_from')->latest('id')
            ->paginate(20);

        $page->getCollection()->transform(fn ($r) => $this->present($r));

        return response()->json($page);
    }

    /** Générer à la demande : ?organization=uuid (défaut : son périmètre), month=AAAA-MM ou from/to. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization' => 'nullable|uuid',
            'month'        => 'nullable|date_format:Y-m',
            'year'         => 'nullable|integer|min:2024|max:2100',
            'from'         => 'nullable|date|required_with:to',
            'to'           => 'nullable|date|after_or_equal:from|before_or_equal:today',
        ]);

        $scope = $this->stats->resolveScope($request->user(), $data['organization'] ?? null);

        [$from, $to] = match (true) {
            ! empty($data['month']) => [Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth(), Carbon::createFromFormat('Y-m', $data['month'])->endOfMonth()],
            ! empty($data['year'])  => [Carbon::create($data['year'])->startOfYear(), Carbon::create($data['year'])->endOfYear()],
            ! empty($data['from'])  => [Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->endOfDay()],
            default                 => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
        };
        abort_if($from->diffInDays($to) > 366, 422, 'Période limitée à un an.');

        $report = $this->reports->generate($scope['organization'], $from, $to, $request->user());

        return response()->json($this->present($report, full: true), 201);
    }

    public function show(Request $request, ActivityReport $report)
    {
        $this->authorizeReport($request, $report);

        return response()->json($this->present($report, full: true));
    }

    public function pdf(Request $request, ActivityReport $report)
    {
        return $this->file($request, $report, $report->pdf_path, 'application/pdf', 'pdf');
    }

    public function xlsx(Request $request, ActivityReport $report)
    {
        return $this->file($request, $report, $report->xlsx_path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    }

    public function destroy(Request $request, ActivityReport $report)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        PrivateFiles::delete($report->pdf_path);
        PrivateFiles::delete($report->xlsx_path);
        $report->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Synthèse rédigée avec l'aide de l'IA : validation par le super admin ──

    public function approveNarrative(Request $request, ActivityReport $report, ActivityReportService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Seul le super administrateur valide les synthèses.');
        abort_unless($report->narrative_status === ActivityReport::NARRATIVE_PENDING, 409, 'Aucune synthèse en attente de validation.');

        $data = $request->validate([
            'summary_fr'                 => 'nullable|string|min:20|max:5000',
            'summary_en'                 => 'nullable|string|max:5000',
            'highlights'                 => 'nullable|array|max:8',
            'highlights.*'               => 'string|max:500',
            'recommendations'            => 'nullable|array|max:10',
            'recommendations.*.priority' => 'required_with:recommendations|in:haute,moyenne,basse',
            'recommendations.*.text'     => 'required_with:recommendations|string|max:500',
            'structures'                 => 'nullable|array',
            'structures.*.name'          => 'required_with:structures|string|max:255',
            'structures.*.comment'       => 'required_with:structures|string|max:1000',
            'note'                       => 'nullable|string|max:500',
        ]);

        $report = $service->approveNarrative($report, $request->user(), collect($data)->except('note')->filter(fn ($v) => $v !== null)->all(), $data['note'] ?? null);

        return response()->json($this->present($report, full: true));
    }

    public function rejectNarrative(Request $request, ActivityReport $report, ActivityReportService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Seul le super administrateur valide les synthèses.');
        abort_unless($report->narrative_status === ActivityReport::NARRATIVE_PENDING, 409, 'Aucune synthèse en attente de validation.');
        $data = $request->validate(['note' => 'required|string|min:5|max:500']);

        return response()->json($this->present($service->rejectNarrative($report, $request->user(), $data['note']), full: true));
    }

    public function regenerateNarrative(Request $request, ActivityReport $report, ActivityReportService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Seul le super administrateur relance la rédaction.');
        abort_if($report->delivered_at !== null && $report->narrative_status === ActivityReport::NARRATIVE_APPROVED, 409, 'Rapport déjà publié avec sa synthèse.');

        return response()->json($this->present($service->regenerateNarrative($report, $request->user()), full: true));
    }

    private function file(Request $request, ActivityReport $report, ?string $path, string $mime, string $ext)
    {
        $this->authorizeReport($request, $report);
        $full = PrivateFiles::path($path);
        abort_unless($full, 404, 'Fichier introuvable.');

        $name = 'DocTrack-' . $report->reference . '-' . str_replace([' ', '/'], '-', $report->period_label) . '.' . $ext;

        return response()->download($full, $name, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeReport(Request $request, ActivityReport $report): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return;
        }
        abort_unless($report->organization && $this->access->can($user, 'stats.view', $report->organization), 403);
    }

    private function present(ActivityReport $r, bool $full = false): array
    {
        return [
            'uuid'         => $r->uuid,
            'reference'    => $r->reference,
            'title'        => $r->title,
            'scope'        => $r->organization ? ['uuid' => $r->organization->uuid, 'name' => $r->organization->name] : 'national',
            'period'       => ['from' => $r->period_from->toDateString(), 'to' => $r->period_to->toDateString(), 'label' => $r->period_label],
            'level'        => $r->analysis['level'] ?? null,
            'score'        => $r->analysis['score'] ?? null,
            'alerts_count' => count($r->analysis['alerts'] ?? []),
            'automatic'    => $r->automatic,
            'pdf_url'      => url('/api/v2/reports/' . $r->uuid . '/pdf'),
            'xlsx_url'     => url('/api/v2/reports/' . $r->uuid . '/xlsx'),
            'created_at'   => $r->created_at,
            'delivered_at' => $r->delivered_at,
            'narrative_status' => $r->narrative_status,
        ] + ($full ? [
            'analysis'  => $r->analysis,
            'data'      => $r->data,
            // Brouillon visible du seul super admin tant qu'il n'est pas validé.
            'narrative' => $r->narrative_status === ActivityReport::NARRATIVE_APPROVED || request()->user()?->isSuperAdmin() ? $r->narrative : null,
            'narrative_review' => [
                'reviewed_at' => $r->narrative_reviewed_at,
                'note'        => $r->narrative_review_note,
                'attempts'    => request()->user()?->isSuperAdmin() ? $r->narrative_attempts : null,
            ],
        ] : []);
    }
}

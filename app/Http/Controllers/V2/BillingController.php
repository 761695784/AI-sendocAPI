<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\BillableService;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\PriceRule;
use App\Services\Billing\BillingManager;
use App\Services\Billing\BillingReport;
use App\Services\Stats\StatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tarifs (super admin) et rapport de reversement (responsables).
 */
class BillingController extends Controller
{
    public function __construct(private BillingManager $billing, private BillingReport $report, private StatsService $stats) {}

    /** Catalogue et tarifs en vigueur (+ historique). */
    public function services(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        foreach (array_keys(config('billing.catalog')) as $code) {
            $this->billing->quote($code); // crée les tarifs initiaux si besoin
        }

        return response()->json([
            'mode' => config('billing.mode'),
            'data' => BillableService::with(['priceRules' => fn ($q) => $q->with('organization:id,uuid,name')->orderByDesc('valid_from')])->get()
                ->map(fn ($s) => [
                    'code'        => $s->code,
                    'name'        => $s->name,
                    'description' => $s->description,
                    'current'     => $this->presentRule($this->billing->quote($s->code)),
                    'history'     => $s->priceRules->map(fn ($r) => $this->presentRule($r))->values(),
                ]),
        ]);
    }

    /** Nouveau tarif (national ou propre à une institution) ; l'ancien est clôturé. */
    public function setPrice(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'service'             => 'required|string|in:' . implode(',', array_keys(config('billing.catalog'))),
            'amount'              => 'required|integer|min:0|max:1000000',
            'authority_share_pct' => 'required|integer|min:0|max:100',
            'institution_uuid'    => 'nullable|uuid|exists:organizations,uuid',
            'valid_from'          => 'nullable|date|after_or_equal:today',
            'note'                => 'nullable|string|max:255',
        ]);

        $institution = null;
        if (! empty($data['institution_uuid'])) {
            $institution = Organization::with('type')->where('uuid', $data['institution_uuid'])->firstOrFail();
            abort_unless($institution->type?->level === OrganizationType::LEVEL_INSTITUTION, 422, 'Un tarif particulier se définit au niveau d\'une institution.');
        }

        $rule = $this->billing->setPrice($data['service'], $data['amount'], $data['authority_share_pct'], $institution,
            $request->user(), $data['valid_from'] ?? null, $data['note'] ?? null);

        return response()->json($this->presentRule($rule->load('organization')), 201);
    }

    /** Rapport de reversement : ?month=2026-10 (ou from/to) & organization=uuid ; ?format=csv. */
    public function report(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m', 'organization' => 'nullable|uuid', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $user = $request->user();

        if ($request->query('organization') || ! $user->isSuperAdmin()) {
            $scope = $this->stats->resolveScope($user, $request->query('organization'));
            abort_unless($user->isSuperAdmin() || app(\App\Services\Access\OrganizationAccess::class)->can($user, 'billing.view', $scope['organization']), 403);
        } else {
            $scope = ['organization' => null, 'ids' => null];
        }

        [$from, $to] = $request->query('month')
            ? [Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth(), Carbon::createFromFormat('Y-m', $request->query('month'))->endOfMonth()]
            : [Carbon::parse($request->query('from', now()->startOfMonth()))->startOfDay(), Carbon::parse($request->query('to', now()))->endOfDay()];

        $report = $this->report->build($scope['ids'], $from, $to) + [
            'scope' => $scope['organization'] ? ['uuid' => $scope['organization']->uuid, 'name' => $scope['organization']->name] : 'national',
        ];

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($report) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Service', 'Démarches', 'Perçu (FCFA)', 'Part autorités', 'Part DocTrack', 'Exonérations', 'Pilote (gratuit)', 'Valeur théorique pilote', 'En attente'], ';');
                foreach ($report['by_service'] as $r) {
                    fputcsv($out, [$r['name'], $r['count'], $r['collected'], $r['authority_share'], $r['platform_share'], $r['exempted_count'], $r['pilot_count'], $r['pilot_theoretical_value'], $r['pending_amount']], ';');
                }
                $t = $report['totals'];
                fputcsv($out, ['TOTAL', $t['count'], $t['collected'], $t['authority_share'], $t['platform_share'], $t['exempted_count'], $t['pilot_count'], $t['pilot_theoretical_value'], $t['pending_amount']], ';');
                fclose($out);
            }, 'doctrack-reversement-' . $report['period']['from'] . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json($report);
    }

    private function presentRule(PriceRule $r): array
    {
        return [
            'id'                  => $r->id,
            'amount'              => $r->amount,
            'currency'            => $r->currency,
            'authority_share_pct' => $r->authority_share_pct,
            'institution'         => $r->organization ? ['uuid' => $r->organization->uuid, 'name' => $r->organization->name] : null,
            'valid_from'          => $r->valid_from,
            'valid_to'            => $r->valid_to,
            'note'                => $r->note,
        ];
    }
}

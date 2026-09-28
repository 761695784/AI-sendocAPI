<?php

namespace App\Services\Billing;

use App\Models\Charge;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rapport de reversement (mensuel) : ce qui a été perçu, la part des
 * autorités, la part DocTrack, les exonérations, et — en phase pilote —
 * la valeur théorique de ce qui aurait été perçu.
 */
class BillingReport
{
    /** @param int[]|null $ids périmètre (null = national) */
    public function build(?array $ids, CarbonInterface $from, CarbonInterface $to): array
    {
        $charges = Charge::with(['service', 'organization'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids ?: [0]))
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $byService = $charges->groupBy(fn ($c) => $c->service->code)
            ->map(fn (Collection $rows, $code) => $this->totals($rows) + ['service' => $code, 'name' => $rows->first()->service->name])
            ->values();

        $byInstitution = $charges->groupBy(fn ($c) => $c->organization?->institution()->name ?? 'En ligne (sans structure)')
            ->map(fn (Collection $rows, $name) => ['institution' => $name] + $this->totals($rows))
            ->values();

        return [
            'period'          => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'mode'            => config('billing.mode'),
            'totals'          => $this->totals($charges),
            'by_service'      => $byService,
            'by_institution'  => $byInstitution,
        ];
    }

    private function totals(Collection $rows): array
    {
        $paid     = $rows->where('status', Charge::STATUS_PAID);
        $waived   = $rows->where('status', Charge::STATUS_WAIVED_PILOT);
        $exempted = $rows->where('status', Charge::STATUS_EXEMPTED);
        $pending  = $rows->where('status', Charge::STATUS_PENDING);

        return [
            'count'                 => $rows->whereNotIn('status', [Charge::STATUS_CANCELLED])->count(),
            'collected'             => (int) $paid->sum('amount'),
            'authority_share'       => (int) $paid->sum('authority_amount'),
            'platform_share'        => (int) $paid->sum('platform_amount'),
            'paid_count'            => $paid->count(),
            'exempted_count'        => $exempted->count(),
            'exempted_value'        => (int) $exempted->sum('list_amount'),
            'pilot_count'           => $waived->count(),
            // Ce qui aurait été perçu hors pilote (argument pour les autorités).
            'pilot_theoretical_value' => (int) $waived->sum('list_amount'),
            'pending_count'         => $pending->count(),
            'pending_amount'        => (int) $pending->sum('amount'),
        ];
    }
}

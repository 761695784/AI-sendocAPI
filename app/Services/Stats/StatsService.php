<?php

namespace App\Services\Stats;

use App\Models\CollectionRequest;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Restitution;
use App\Models\User;
use App\Services\Access\OrganizationAccess;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistiques par périmètre (structure, direction, institution, national).
 * Un périmètre = une organisation et toute sa descendance ; null = national (super admin).
 * Les durées sont en heures ; les médianes sont calculées en PHP (portable MySQL/SQLite).
 */
class StatsService
{
    public function __construct(private OrganizationAccess $access) {}

    /**
     * @return array{organization: ?Organization, ids: ?array}
     */
    public function resolveScope(User $user, ?string $organizationUuid): array
    {
        if ($organizationUuid) {
            $org = Organization::where('uuid', $organizationUuid)->firstOrFail();
            abort_unless($this->access->can($user, 'stats.view', $org), 403, 'Ce périmètre ne vous est pas accessible.');

            return ['organization' => $org, 'ids' => Organization::subtreeOf($org)->pluck('id')->all()];
        }

        if ($user->isSuperAdmin()) {
            return ['organization' => null, 'ids' => null];
        }

        // Périmètre par défaut : l'organisation la plus haute où l'utilisateur voit les statistiques.
        $org = $this->access->memberships($user)
            ->map->organization
            ->filter(fn ($o) => $this->access->can($user, 'stats.view', $o))
            ->sortBy('depth')
            ->first();
        abort_unless($org, 403, 'Vous n\'avez pas accès aux statistiques.');

        return ['organization' => $org, 'ids' => Organization::subtreeOf($org)->pluck('id')->all()];
    }

    /** Indicateurs clés du périmètre sur la période. */
    public function overview(?array $ids, CarbonInterface $from, CarbonInterface $to): array
    {
        $sla    = config('doctrack.sla');
        $period = [$from, $to];

        // ── Déclarations ──────────────────────────────────────
        $counter = $this->scoped(DeclarationDePerte::query(), 'organization_id', $ids)
            ->where('channel', DeclarationDePerte::CHANNEL_COUNTER)->whereBetween('created_at', $period);
        $declarations = [
            'counter'   => (clone $counter)->count(),
            'cancelled' => (clone $counter)->where('status', DeclarationDePerte::STATUS_CANCELLED)->count(),
            'online'    => $ids === null
                ? DeclarationDePerte::where('channel', DeclarationDePerte::CHANNEL_ONLINE)->whereBetween('created_at', $period)->count()
                : null, // non rattachées à une structure : national seulement
        ];

        // ── Documents trouvés et stock sous garde ─────────────
        $custody = $this->scoped(Document::query(), 'custody_organization_id', $ids);
        $stock   = (clone $custody)->whereIn('lifecycle_status', [Document::LIFECYCLE_IN_CUSTODY, Document::LIFECYCLE_MATCHED, Document::LIFECYCLE_RESTITUTION_PENDING])
            ->pluck('custody_since');
        $entered = (clone $custody)->whereBetween('custody_since', $period);
        $enteredCount = (clone $entered)->count();

        $found = [
            'deposited_at_counter'  => (clone $custody)->where('found_channel', Document::CHANNEL_COUNTER)->whereBetween('created_at', $period)->count(),
            'collected_from_finders'=> $this->scoped(CollectionRequest::query(), 'organization_id', $ids)
                ->where('status', CollectionRequest::STATUS_COLLECTED)->whereBetween('collected_at', $period)->count(),
            'published_online'      => $ids === null
                ? Document::where('found_channel', Document::CHANNEL_CITIZEN)->whereBetween('created_at', $period)->count()
                : null,
            'in_custody_now'        => $stock->count(),
            'custody_age'           => [
                'under_30_days' => $stock->filter(fn ($d) => $d && Carbon::parse($d)->gt(now()->subDays(30)))->count(),
                '30_to_90_days' => $stock->filter(fn ($d) => $d && Carbon::parse($d)->lte(now()->subDays(30)) && Carbon::parse($d)->gt(now()->subDays(90)))->count(),
                'over_90_days'  => $stock->filter(fn ($d) => $d && Carbon::parse($d)->lte(now()->subDays(90)))->count(),
            ],
            // Documents entrés sous garde sur la période et déjà rendus.
            'entered_custody'       => $enteredCount,
            'restitution_rate_pct'  => $enteredCount
                ? round((clone $entered)->where('lifecycle_status', Document::LIFECYCLE_RESTITUTED)->count() / $enteredCount * 100, 1)
                : null,
        ];

        // ── Correspondances ──────────────────────────────────
        $matches = $this->scoped(DocumentMatch::query(), 'organization_id', $ids);
        $reviewed = (clone $matches)->whereNotNull('reviewed_at')->whereBetween('reviewed_at', $period)->get(['created_at', 'reviewed_at']);
        $matchStats = [
            'created'              => (clone $matches)->whereBetween('created_at', $period)->count(),
            'auto_confirmed'       => (clone $matches)->whereBetween('created_at', $period)->where('status', DocumentMatch::STATUS_CONFIRMED)->whereNull('reviewed_by')->count(),
            'confirmed_by_agents'  => (clone $matches)->whereBetween('reviewed_at', $period)->where('status', DocumentMatch::STATUS_CONFIRMED)->count(),
            'rejected'             => (clone $matches)->whereBetween('reviewed_at', $period)->where('status', DocumentMatch::STATUS_REJECTED)->count(),
            'pending_review_now'   => (clone $matches)->where('status', DocumentMatch::STATUS_PENDING_REVIEW)->count(),
            'overdue_reviews_now'  => (clone $matches)->where('status', DocumentMatch::STATUS_PENDING_REVIEW)
                ->where('created_at', '<=', now()->subHours($sla['review_hours']))->count(),
            'median_review_hours'  => $this->median($reviewed->map(fn ($m) => $this->hours($m->created_at, $m->reviewed_at))),
        ];

        // ── Collectes (délai de réaction) ────────────────────
        $collections = $this->collectionStats($this->scoped(CollectionRequest::query(), 'organization_id', $ids), $from, $to);
        if ($ids === null) {
            $collections['unassigned_now'] = CollectionRequest::where('status', CollectionRequest::STATUS_UNASSIGNED)->count();
        }

        // ── Restitutions officielles ─────────────────────────
        $rest = $this->scoped(Restitution::query(), 'organization_id', $ids)->whereBetween('handed_over_at', $period)
            ->with('document:id,custody_since')->get();
        $done = $rest->where('status', Restitution::STATUS_COMPLETED);
        $restitutions = [
            'completed'                 => $done->count(),
            'voided'                    => $rest->where('status', Restitution::STATUS_VOIDED)->count(),
            'by_proxy'                  => $done->where('recipient_type', Restitution::RECIPIENT_PROXY)->count(),
            'without_prior_declaration' => $done->filter(fn ($r) => ! empty($r->checks['without_prior_declaration']))->count(),
            'name_derogations'          => $done->whereNotNull('name_override_reason')->count(),
            'median_days_in_custody'    => $this->median($done->map(fn ($r) => $r->document?->custody_since
                ? round($r->document->custody_since->diffInMinutes($r->handed_over_at) / 1440, 1) : null)),
        ];

        // ── SMS ──────────────────────────────────────────────
        $sms = $this->scoped(NotificationDelivery::query(), 'organization_id', $ids)
            ->where('channel', NotificationDelivery::CHANNEL_SMS)->whereBetween('created_at', $period)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'declarations'   => $declarations,
            'found_documents'=> $found,
            'matches'        => $matchStats,
            'collections'    => $collections,
            'restitutions'   => $restitutions,
            'sms'            => ['sent' => (int) ($sms['sent'] ?? 0), 'failed' => (int) ($sms['failed'] ?? 0), 'skipped' => (int) ($sms['skipped'] ?? 0)],
            'sla'            => $sla,
        ];
    }

    /**
     * Tableau comparatif des structures du périmètre : qui réagit vite, qui a du retard.
     */
    public function structures(?array $ids, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $structures = Organization::query()
            ->with('parent:id,name')
            ->whereHas('type', fn ($q) => $q->where('level', OrganizationType::LEVEL_STRUCTURE))
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();

        return $structures->map(function (Organization $org) use ($from, $to) {
            $c = $this->collectionStats(CollectionRequest::where('organization_id', $org->id), $from, $to);

            return [
                'uuid'                     => $org->uuid,
                'name'                     => $org->name,
                'parent'                   => $org->parent?->name,
                'is_active'                => $org->is_active,
                'collections_assigned'     => $c['assigned'],
                'median_first_contact_hours' => $c['median_first_contact_hours'],
                'contacted_within_sla_pct' => $c['contacted_within_sla_pct'],
                'collections_overdue_now'  => $c['overdue_now'],
                'collected'                => $c['collected'],
                'median_hours_to_collect'  => $c['median_hours_to_collect'],
                'in_custody_now'           => Document::where('custody_organization_id', $org->id)
                    ->whereIn('lifecycle_status', [Document::LIFECYCLE_IN_CUSTODY, Document::LIFECYCLE_MATCHED, Document::LIFECYCLE_RESTITUTION_PENDING])->count(),
                'pending_reviews_now'      => DocumentMatch::where('organization_id', $org->id)->where('status', DocumentMatch::STATUS_PENDING_REVIEW)->count(),
                'counter_declarations'     => DeclarationDePerte::where('organization_id', $org->id)->where('channel', DeclarationDePerte::CHANNEL_COUNTER)
                    ->whereBetween('created_at', [$from, $to])->count(),
                'restitutions'             => Restitution::where('organization_id', $org->id)->where('status', Restitution::STATUS_COMPLETED)
                    ->whereBetween('handed_over_at', [$from, $to])->count(),
            ];
        })
            // Les structures en difficulté en premier.
            ->sortBy([['collections_overdue_now', 'desc'], ['median_first_contact_hours', 'desc']])
            ->values();
    }

    /** Évolution d'un indicateur par jour, semaine ou mois. */
    public function timeseries(?array $ids, string $metric, string $interval, CarbonInterface $from, CarbonInterface $to): array
    {
        [$query, $column] = match ($metric) {
            'counter_declarations' => [$this->scoped(DeclarationDePerte::query(), 'organization_id', $ids)->where('channel', DeclarationDePerte::CHANNEL_COUNTER), 'created_at'],
            'deposits'             => [$this->scoped(Document::query(), 'custody_organization_id', $ids)->where('found_channel', Document::CHANNEL_COUNTER), 'created_at'],
            'collections_assigned' => [$this->scoped(CollectionRequest::query(), 'organization_id', $ids)->whereNotNull('assigned_at'), 'assigned_at'],
            'collected'            => [$this->scoped(CollectionRequest::query(), 'organization_id', $ids)->where('status', CollectionRequest::STATUS_COLLECTED), 'collected_at'],
            'matches_confirmed'    => [$this->scoped(DocumentMatch::query(), 'organization_id', $ids)->where('status', DocumentMatch::STATUS_CONFIRMED), 'updated_at'],
            'restitutions'         => [$this->scoped(Restitution::query(), 'organization_id', $ids)->where('status', Restitution::STATUS_COMPLETED), 'handed_over_at'],
            default                => abort(422, 'Indicateur inconnu.'),
        };

        $format = ['day' => 'Y-m-d', 'week' => 'o-\WW', 'month' => 'Y-m'][$interval] ?? abort(422, 'Intervalle inconnu.');
        $counts = $query->whereBetween($column, [$from, $to])->pluck($column)
            ->countBy(fn ($d) => Carbon::parse($d)->format($format));

        // Toutes les périodes, même vides (courbe continue).
        $points = [];
        $cursor = Carbon::parse($from)->startOf($interval === 'week' ? 'week' : $interval);
        while ($cursor->lte($to)) {
            $key = $cursor->format($format);
            $points[] = ['period' => $key, 'value' => (int) ($counts[$key] ?? 0)];
            $cursor->add(1, $interval);
        }

        return $points;
    }

    /** Collectes en retard (aucun appel dans le délai), les plus anciennes d'abord. */
    public function overdueCollections(?array $ids): Collection
    {
        return $this->scoped(CollectionRequest::query(), 'organization_id', $ids)
            ->with(['organization:id,uuid,name', 'document:id,uuid,reference,found_place,Location,document_type_id', 'document.documentType:id,TypeName'])
            ->where('status', CollectionRequest::STATUS_ASSIGNED)
            ->whereNull('first_contacted_at')
            ->where('assigned_at', '<=', now()->subHours(config('doctrack.sla.first_contact_hours')))
            ->orderBy('assigned_at')
            ->get()
            ->map(fn (CollectionRequest $c) => [
                'uuid'          => $c->uuid,
                'structure'     => $c->organization?->only(['uuid', 'name']),
                'reference'     => $c->document?->reference,
                'document_type' => $c->document?->documentType?->TypeName,
                'place'         => $c->document?->found_place ?? $c->document?->Location,
                'assigned_at'   => $c->assigned_at,
                'hours_waiting' => (int) $c->assigned_at->diffInHours(now()),
                'priority'      => $c->owner_identified_at !== null,
                'escalated'     => $c->escalated_at !== null,
            ]);
    }

    private function collectionStats(Builder $base, CarbonInterface $from, CarbonInterface $to): array
    {
        $slaHours = config('doctrack.sla.first_contact_hours');
        $assigned = (clone $base)->whereBetween('assigned_at', [$from, $to])
            ->get(['assigned_at', 'first_contacted_at', 'collected_at', 'status']);

        // Délai mesurable : trouveur appelé, ou délai déjà écoulé sans appel.
        $measurable = $assigned->filter(fn ($c) => $c->first_contacted_at || $c->assigned_at->lte(now()->subHours($slaHours)));
        $inTime     = $measurable->filter(fn ($c) => $c->first_contacted_at && $this->hours($c->assigned_at, $c->first_contacted_at) <= $slaHours);
        $collected  = $assigned->where('status', CollectionRequest::STATUS_COLLECTED);

        return [
            'assigned'                   => $assigned->count(),
            'median_first_contact_hours' => $this->median($assigned->filter(fn ($c) => $c->first_contacted_at)
                ->map(fn ($c) => $this->hours($c->assigned_at, $c->first_contacted_at))),
            'contacted_within_sla_pct'   => $measurable->count() ? round($inTime->count() / $measurable->count() * 100, 1) : null,
            'collected'                  => $collected->count(),
            'median_hours_to_collect'    => $this->median($collected->filter(fn ($c) => $c->collected_at)
                ->map(fn ($c) => $this->hours($c->assigned_at, $c->collected_at))),
            'cancelled'                  => $assigned->where('status', CollectionRequest::STATUS_CANCELLED)->count(),
            'open_now'                   => (clone $base)->whereIn('status', CollectionRequest::OPEN_STATUSES)->count(),
            'overdue_now'                => (clone $base)->where('status', CollectionRequest::STATUS_ASSIGNED)->whereNull('first_contacted_at')
                ->where('assigned_at', '<=', now()->subHours($slaHours))->count(),
        ];
    }

    private function scoped(Builder $query, string $column, ?array $ids): Builder
    {
        return $ids === null ? $query : $query->whereIn($column, $ids ?: [0]);
    }

    private function hours($start, $end): float
    {
        return round(Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60, 1);
    }

    private function median(Collection $values): ?float
    {
        $v = $values->filter(fn ($x) => $x !== null)->sort()->values();
        $n = $v->count();
        if ($n === 0) {
            return null;
        }

        return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
    }
}

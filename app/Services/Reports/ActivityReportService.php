<?php

namespace App\Services\Reports;

use App\Models\ActivityReport;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\PrivateFiles;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Génère un rapport d'activité V2 (PDF officiel + classeur Excel) pour un
 * périmètre (national, institution, direction, structure) et une période.
 */
class ActivityReportService
{
    public function __construct(
        private ReportBuilder $builder,
        private ReportAnalyzer $analyzer,
        private Notifier $notifier,
        private ReportNarrator $narrator,
    ) {}

    public function generate(?Organization $scope, CarbonInterface $from, CarbonInterface $to, ?User $author = null, bool $automatic = false): ActivityReport
    {
        $scope?->loadMissing('type');
        $ids = $scope ? Organization::subtreeOf($scope)->pluck('id')->all() : null;

        $data     = $this->builder->build($scope, $ids, $from, $to);
        $analysis = $this->analyzer->analyze($data);

        // Un seul rapport par périmètre et période : on remplace l'ancien.
        ActivityReport::where('organization_id', $scope?->id)
            ->whereDate('period_from', $from->toDateString())->whereDate('period_to', $to->toDateString())
            ->get()->each(function (ActivityReport $old) {
                PrivateFiles::delete($old->pdf_path);
                PrivateFiles::delete($old->xlsx_path);
                $old->delete();
            });

        $report = ActivityReport::create([
            'organization_id' => $scope?->id,
            'period_from'     => $from->toDateString(),
            'period_to'       => $to->toDateString(),
            'period_label'    => $data['meta']['period']['label'],
            'title'           => $data['meta']['title'],
            'data'            => $data,
            'analysis'        => $analysis,
            'automatic'       => $automatic,
            'generated_by'    => $author?->id,
        ]);

        // Partie rédigée avec l'aide de l'IA (chiffres vérifiés) — publiée après validation.
        $this->narrate($report);

        $this->render($report);

        activity()->causedBy($author)->performedOn($report)
            ->withProperties(['reference' => $report->reference, 'scope' => $scope?->name ?? 'national', 'automatic' => $automatic])
            ->log('Rapport d\'activité généré');

        return $report;
    }

    /**
     * Le 1er du mois : rapport du mois écoulé pour le national et chaque institution,
     * déposé dans la boîte de réception et envoyé par email aux responsables.
     */
    public function generateMonthly(?CarbonInterface $month = null): array
    {
        $month = Carbon::parse($month ?? now()->subMonthNoOverflow())->startOfMonth();
        $from  = $month->copy()->startOfMonth();
        $to    = $month->copy()->endOfMonth();
        $made  = [];

        $national = $this->generate(null, $from, $to, null, true);
        $this->deliverOrHold($national);
        $made[] = $national;

        Organization::with('type')->where('is_active', true)
            ->whereHas('type', fn ($q) => $q->where('level', OrganizationType::LEVEL_INSTITUTION))
            ->get()->each(function (Organization $institution) use ($from, $to, &$made) {
                $report = $this->generate($institution, $from, $to, null, true);
                $this->deliverOrHold($report);
                $made[] = $report;
            });

        return $made;
    }

    // ── Rédaction assistée par l'IA ──────────────────────────────────

    /** Demande le texte à l'IA ; état « à valider », « validé d'office » ou « échec ». */
    public function narrate(ActivityReport $report): void
    {
        if (! $this->narrator->enabled()) {
            $report->forceFill(['narrative_status' => ActivityReport::NARRATIVE_NONE])->save();

            return;
        }

        $result = $this->narrator->write($report);
        $report->forceFill([
            'narrative'          => $result['narrative'],
            'narrative_attempts' => $result['attempts'],
            'narrative_status'   => match (true) {
                $result['narrative'] === null           => ActivityReport::NARRATIVE_FAILED,
                config('ai.reports.require_validation') => ActivityReport::NARRATIVE_PENDING,
                default                                 => ActivityReport::NARRATIVE_APPROVED,
            },
            'narrative_reviewed_by' => null,
            'narrative_reviewed_at' => null,
            'narrative_review_note' => null,
        ])->save();
    }

    /** Validation par le super admin (avec corrections éventuelles), puis publication et envoi. */
    public function approveNarrative(ActivityReport $report, User $reviewer, array $edits = [], ?string $note = null): ActivityReport
    {
        $narrative = array_replace($report->narrative ?? [], array_filter([
            'summary_fr'      => $edits['summary_fr'] ?? null,
            'summary_en'      => $edits['summary_en'] ?? null,
            'highlights'      => $edits['highlights'] ?? null,
            'recommendations' => $edits['recommendations'] ?? null,
            'structures'      => $edits['structures'] ?? null,
        ], fn ($v) => $v !== null));
        if ($edits) {
            $narrative['edited_by_reviewer'] = true;
        }

        $report->forceFill([
            'narrative'             => $narrative,
            'narrative_status'      => ActivityReport::NARRATIVE_APPROVED,
            'narrative_reviewed_by' => $reviewer->id,
            'narrative_reviewed_at' => now(),
            'narrative_review_note' => $note,
        ])->save();

        return $this->publishAfterReview($report, $reviewer, 'Synthèse IA validée');
    }

    /** Refus : le rapport part avec l'analyse par règles seule. */
    public function rejectNarrative(ActivityReport $report, User $reviewer, string $note): ActivityReport
    {
        $report->forceFill([
            'narrative_status'      => ActivityReport::NARRATIVE_REJECTED,
            'narrative_reviewed_by' => $reviewer->id,
            'narrative_reviewed_at' => now(),
            'narrative_review_note' => $note,
        ])->save();

        return $this->publishAfterReview($report, $reviewer, 'Synthèse IA refusée');
    }

    public function regenerateNarrative(ActivityReport $report, User $author): ActivityReport
    {
        $this->narrate($report);
        $this->render($report);
        activity()->causedBy($author)->performedOn($report)->withProperties(['status' => $report->narrative_status])->log('Synthèse IA régénérée');

        return $report;
    }

    private function publishAfterReview(ActivityReport $report, User $reviewer, string $log): ActivityReport
    {
        $this->render($report);
        if ($report->automatic && ! $report->delivered_at) {
            $this->deliver($report, $report->organization);
        }
        activity()->causedBy($reviewer)->performedOn($report)
            ->withProperties(['reference' => $report->reference, 'note' => $report->narrative_review_note])
            ->log($log);

        return $report->fresh();
    }

    /** Rapport mensuel : envoyé tout de suite, sauf si une synthèse IA attend validation. */
    private function deliverOrHold(ActivityReport $report): void
    {
        if ($report->narrative_status === ActivityReport::NARRATIVE_PENDING) {
            $this->notifier->inboxSuperAdmins('report.narrative_pending', [
                'reference' => $report->reference,
                'period'    => $report->period_label,
                'scope'     => $report->organization?->name ?? 'National',
            ], ['subject' => $report, 'dedupe' => 'report.narrative_pending:' . $report->id], 'reports/' . $report->uuid);

            return;
        }
        $this->deliver($report, $report->organization);
    }

    /** PDF + Excel (avec la synthèse IA si elle est validée). */
    public function render(ActivityReport $report): void
    {
        $data     = $report->data;
        $analysis = $report->analysis;
        $base     = 'rapports/v2/' . $report->reference;

        $pdf = Pdf::loadView('rapports.activite_v2', ['r' => $report, 'd' => $data, 'a' => $analysis, 'n' => $report->publishedNarrative()])
            ->setPaper('A4', 'portrait')
            ->setOptions(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        PrivateFiles::put($base . '.pdf', $pdf->output());

        $tmp = tempnam(sys_get_temp_dir(), 'dtx');
        $this->workbook($data, $analysis, $report->publishedNarrative())->save($tmp);
        PrivateFiles::put($base . '.xlsx', file_get_contents($tmp));
        @unlink($tmp);

        $report->forceFill(['pdf_path' => $base . '.pdf', 'xlsx_path' => $base . '.xlsx'])->save();
    }

    private function deliver(ActivityReport $report, ?Organization $institution): void
    {
        $report->forceFill(['delivered_at' => now()])->save();

        $params = [
            'period'    => $report->period_label,
            'scope'     => $institution?->name ?? 'National',
            'reference' => $report->reference,
            'summary'   => $report->publishedNarrative()['summary_fr'] ?? ($report->analysis['summary'] ?? ''),
            'alerts'    => count($report->analysis['alerts'] ?? []),
        ];
        $context = ['subject' => $report, 'organization_id' => $institution?->id, 'dedupe' => 'report.monthly:' . $report->id];
        $link    = 'reports/' . $report->uuid;

        if ($institution) {
            $this->notifier->inbox('report.monthly', $institution, $params, $context, $link, priority: $params['alerts'] > 0);
            $admins = User::whereIn('id', OrganizationMembership::where('organization_id', $institution->id)
                ->where('role', OrganizationMembership::ROLE_INSTITUTION_ADMIN)->whereNull('ends_at')->pluck('user_id'))->get();
        } else {
            $this->notifier->inboxSuperAdmins('report.monthly', $params, $context, $link);
            $admins = User::role(User::ROLE_SUPER_ADMIN, 'api')->get();
        }

        foreach ($admins as $admin) {
            if ($admin->email) {
                $this->notifier->mailTo('report.monthly', $admin->email, $params,
                    ['subject' => $report, 'recipient' => $admin, 'organization_id' => $institution?->id, 'dedupe' => 'report.monthly.mail:' . $report->id . ':' . $admin->id]);
            }
        }
    }

    private function workbook(array $d, array $a, ?array $n = null): XlsxWriter
    {
        $o = $d['overview'];
        $x = new XlsxWriter();

        $x->addSheet('Synthèse', [
            ['Indicateur', 'Valeur', 'Période précédente', 'Variation (%)'],
            ['Périmètre', $d['meta']['scope']['name'], null, null],
            ['Période', $d['meta']['period']['label'], null, null],
            ['Niveau global', $a['level'] . ' (' . $a['score'] . '/100)', null, null],
            ...collect([
                'counter_declarations'       => 'Déclarations au guichet',
                'deposited_at_counter'       => 'Documents déposés au guichet',
                'collected_from_finders'     => 'Documents récupérés chez des trouveurs',
                'matches_created'            => 'Correspondances détectées',
                'collections_assigned'       => 'Collectes affectées',
                'restitutions'               => 'Restitutions officielles',
                'median_first_contact_hours' => '1er appel au trouveur (h, médiane)',
            ])->map(fn ($label, $k) => [$label, $d['comparison'][$k]['current'], $d['comparison'][$k]['previous'], $d['comparison'][$k]['change_pct']])->values()->all(),
            ['Documents sous garde (aujourd\'hui)', $o['found_documents']['in_custody_now'], null, null],
            ['Taux de restitution des entrées (%)', $o['found_documents']['restitution_rate_pct'], null, null],
            ['Correspondances à vérifier (aujourd\'hui)', $o['matches']['pending_review_now'], null, null],
            ['Appelés dans le délai (%)', $o['collections']['contacted_within_sla_pct'], null, null],
            ['Collectes en retard (aujourd\'hui)', $o['collections']['overdue_now'], null, null],
            ['SMS envoyés', $o['sms']['sent'], null, null],
            ['SMS en échec', $o['sms']['failed'], null, null],
        ], [42, 22, 20, 16]);

        $x->addSheet('Structures', array_merge([[
            'Structure', 'Rattachement', 'Collectes reçues', '1er appel (h, médiane)', 'Appelés dans le délai (%)',
            'Collectes en retard', 'Récupérés', 'Sous garde', 'Correspondances à vérifier', 'Déclarations', 'Restitutions',
        ]], collect($d['structures'])->map(fn ($s) => [
            $s['name'], $s['parent'], $s['collections_assigned'], $s['median_first_contact_hours'], $s['contacted_within_sla_pct'],
            $s['collections_overdue_now'], $s['collected'], $s['in_custody_now'], $s['pending_reviews_now'], $s['counter_declarations'], $s['restitutions'],
        ])->all()), [34, 28]);

        $series = $d['evolution']['series'];
        $x->addSheet('Évolution', array_merge(
            [['Période', 'Déclarations', 'Dépôts', 'Collectes affectées', 'Récupérés', 'Restitutions']],
            collect($series['counter_declarations'])->map(fn ($p, $i) => [
                $p['period'], $p['value'], $series['deposits'][$i]['value'] ?? 0, $series['collections_assigned'][$i]['value'] ?? 0,
                $series['collected'][$i]['value'] ?? 0, $series['restitutions'][$i]['value'] ?? 0,
            ])->all()
        ), [16]);

        $x->addSheet('Types de documents', array_merge([['Type', 'Déclarés', 'Trouvés', 'Restitués']],
            collect($d['by_document_type'])->map(fn ($t) => [$t['type'], $t['declared'], $t['found'], $t['restituted']])->all()));

        $x->addSheet('Régions', array_merge([['Région', 'Déclarations', 'Documents trouvés', 'Restitutions']],
            collect($d['by_region'])->map(fn ($r) => [$r['region'], $r['declarations'], $r['found'], $r['restitutions']])->all()));

        $x->addSheet('Finances', array_merge([['Service', 'Démarches', 'Perçu (FCFA)', 'Part autorités', 'Part DocTrack', 'Exonérations', 'Pilote (gratuit)', 'Valeur théorique pilote', 'En attente (FCFA)']],
            collect($d['billing']['by_service'])->map(fn ($s) => [$s['name'], $s['count'], $s['collected'], $s['authority_share'], $s['platform_share'],
                $s['exempted_count'], $s['pilot_count'], $s['pilot_theoretical_value'], $s['pending_amount']])->all()), [34]);

        $x->addSheet('Collectes en retard', array_merge([['Structure', 'Référence', 'Type', 'Lieu', 'Attente (h)', 'Prioritaire', 'Alerte hiérarchique']],
            collect($d['overdue_collections'])->map(fn ($c) => [$c['structure']['name'] ?? '', $c['reference'], $c['document_type'], $c['place'], $c['hours_waiting'], $c['priority'], $c['escalated']])->all()));

        $x->addSheet('Analyse', array_merge(
            [['Type', 'Message']],
            collect($a['alerts'])->map(fn ($al) => ['Alerte (' . $al['severity'] . ')', $al['message']])->all(),
            collect($a['positives'])->map(fn ($p) => ['Point positif', $p])->all(),
            collect($a['recommendations'])->map(fn ($r) => ['Recommandation', $r])->all(),
        ), [22, 110]);

        if ($n) {
            $rows = [['Rubrique', 'Texte'], ['Synthèse', $n['summary_fr'] ?? '']];
            foreach ($n['highlights'] ?? [] as $h) {
                $rows[] = ['Constat', $h];
            }
            foreach ($n['structures'] ?? [] as $st) {
                $rows[] = [$st['name'], $st['comment']];
            }
            foreach ($n['recommendations'] ?? [] as $reco) {
                $rows[] = ['Recommandation (' . $reco['priority'] . ')', $reco['text']];
            }
            if (! empty($n['summary_en'])) {
                $rows[] = ['Summary (English)', $n['summary_en']];
            }
            $rows[] = ['Mention', 'Texte rédigé avec l\'aide de l\'IA (' . ($n['model'] ?? '') . '), chiffres vérifiés automatiquement, validé avant publication.'];
            $x->addSheet('Commentaire', $rows, [30, 120]);
        }

        return $x;
    }
}

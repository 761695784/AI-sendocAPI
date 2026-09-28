<?php

namespace App\Services\Reports;

/**
 * Analyse à base de règles explicites (chaque alerte cite le chiffre qui la déclenche).
 * Rien n'est « deviné » : un responsable doit pouvoir refaire le raisonnement.
 */
class ReportAnalyzer
{
    public function analyze(array $data): array
    {
        $o   = $data['overview'];
        $cmp = $data['comparison'];
        $sla = $data['meta']['sla'];

        $alerts = [];
        $positives = [];
        $recommendations = [];
        $score = 100;

        $c = $o['collections'];
        if ($c['assigned'] > 0 && $c['contacted_within_sla_pct'] !== null) {
            if ($c['contacted_within_sla_pct'] < 70) {
                $alerts[] = $this->alert('critique', "Seulement {$c['contacted_within_sla_pct']} % des trouveurs ont été appelés dans les {$sla['first_contact_hours']} h.");
                $recommendations[] = 'Désigner dans chaque structure un agent chargé des collectes et consulter la boîte de réception chaque matin.';
                $score -= 25;
            } elseif ($c['contacted_within_sla_pct'] >= 90) {
                $positives[] = "{$c['contacted_within_sla_pct']} % des trouveurs appelés dans le délai de {$sla['first_contact_hours']} h.";
            }
        }
        if ($c['overdue_now'] > 0) {
            $alerts[] = $this->alert('important', "{$c['overdue_now']} collecte(s) en attente d'un premier appel au-delà du délai.");
            $score -= min(15, $c['overdue_now'] * 3);
        }

        $m = $o['matches'];
        if ($m['overdue_reviews_now'] > 0) {
            $alerts[] = $this->alert('important', "{$m['overdue_reviews_now']} correspondance(s) attendent une vérification depuis plus de {$sla['review_hours']} h.");
            $recommendations[] = 'Traiter la file « Correspondances à vérifier » : chaque correspondance confirmée déclenche l\'avertissement du propriétaire.';
            $score -= 10;
        }
        $reviewed = $m['confirmed_by_agents'] + $m['rejected'];
        if ($reviewed >= 10 && $m['rejected'] / $reviewed > 0.5) {
            $alerts[] = $this->alert('information', 'Plus de la moitié des correspondances vérifiées ont été rejetées : les seuils du moteur peuvent être relevés.');
        }
        if ($m['auto_confirmed'] > 0) {
            $positives[] = "{$m['auto_confirmed']} correspondance(s) confirmée(s) automatiquement grâce au numéro du document.";
        }

        $f = $o['found_documents'];
        if (($f['custody_age']['over_90_days'] ?? 0) > 0) {
            $alerts[] = $this->alert('important', "{$f['custody_age']['over_90_days']} document(s) sous garde depuis plus de 90 jours.");
            $recommendations[] = 'Définir avec les autorités une règle de conservation (transfert ou destruction) pour les documents non réclamés.';
            $score -= 5;
        }
        if ($f['restitution_rate_pct'] !== null) {
            $f['restitution_rate_pct'] >= 50
                ? $positives[] = "{$f['restitution_rate_pct']} % des documents entrés sous garde sur la période ont déjà été rendus."
                : $recommendations[] = 'Encourager les déclarations de perte (affichage au guichet, SMS) : sans déclaration, un document trouvé ne retrouve pas son propriétaire.';
        }

        $r = $o['restitutions'];
        if ($r['voided'] > 0) {
            $alerts[] = $this->alert('important', "{$r['voided']} restitution(s) annulée(s) : vérifier les contrôles d'identité au guichet.");
            $score -= 5;
        }
        if ($r['completed'] > 0 && $r['name_derogations'] / $r['completed'] > 0.2) {
            $alerts[] = $this->alert('information', 'Plus de 20 % des restitutions ont nécessité une dérogation sur le nom.');
        }
        if ($r['completed'] > 0) {
            $positives[] = "{$r['completed']} document(s) rendu(s) à leur propriétaire (durée médiane sous garde : " . ($r['median_days_in_custody'] ?? '—') . ' jour(s)).';
        }

        $sms = $o['sms'];
        $smsTotal = $sms['sent'] + $sms['failed'];
        if ($smsTotal >= 10 && $sms['failed'] / $smsTotal > 0.1) {
            $alerts[] = $this->alert('important', 'Plus de 10 % des SMS n\'ont pas été délivrés : vérifier le compte SMS et les numéros des structures.');
            $score -= 5;
        }
        if ($sms['skipped'] > 0) {
            $recommendations[] = "Compléter les numéros de téléphone manquants ({$sms['skipped']} SMS non envoyés faute de numéro).";
        }

        $b = $data['billing']['totals'] ?? null;
        if ($b && $b['count'] > 0 && $b['exempted_count'] / $b['count'] > 0.2) {
            $alerts[] = $this->alert('information', 'Plus de 20 % des frais ont été exonérés sur la période.');
        }
        if ($b && $b['pending_count'] > 0) {
            $alerts[] = $this->alert('information', "{$b['pending_count']} frais en attente de paiement ({$b['pending_amount']} FCFA).");
        }

        $late = collect($data['structures'])->where('collections_overdue_now', '>', 0)->pluck('name');
        if ($late->isNotEmpty()) {
            $recommendations[] = 'Relancer en priorité : ' . $late->take(5)->implode(', ') . ($late->count() > 5 ? '…' : '') . '.';
        }

        $trend = $cmp['counter_declarations'];
        $trendText = $trend['change_pct'] === null ? '' : ($trend['change_pct'] >= 0
            ? " (en hausse de {$trend['change_pct']} % par rapport à la période précédente)"
            : ' (en baisse de ' . abs($trend['change_pct']) . ' % par rapport à la période précédente)');

        $score = max(0, $score);
        $level = $score >= 80 ? 'bon' : ($score >= 55 ? 'à surveiller' : 'critique');

        return [
            'score'           => $score,
            'level'           => $level,
            'summary'         => sprintf(
                '%s : %d déclaration(s) au guichet%s, %d document(s) trouvé(s) entré(s) sous garde, %d collecte(s) auprès de trouveurs et %d restitution(s) officielle(s). Niveau global : %s.',
                $data['meta']['period']['label'], $o['declarations']['counter'], $trendText,
                $f['entered_custody'], $c['assigned'], $r['completed'], $level
            ),
            'alerts'          => $alerts,
            'positives'       => $positives,
            'recommendations' => array_values(array_unique($recommendations)),
        ];
    }

    private function alert(string $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }
}

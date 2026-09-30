<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $r->title }} — {{ $r->period_label }}</title>
<style>
    @page { margin: 30px 34px 56px 34px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.6px; color: #1f2430; line-height: 1.4; }
    table { width: 100%; border-collapse: collapse; }
    h1 { font-size: 20px; margin: 0 0 4px 0; }
    h2 { font-size: 12.5px; margin: 18px 0 6px 0; padding-bottom: 3px; border-bottom: 2px solid #1f2430; text-transform: uppercase; letter-spacing: .5px; }
    h3 { font-size: 10.5px; margin: 10px 0 4px 0; }
    .muted { color: #5b6272; }
    .small { font-size: 8.4px; }
    .cover { border: 2px solid #1f2430; padding: 16px 18px; margin-bottom: 12px; }
    .badge { display: inline-block; padding: 3px 9px; border-radius: 10px; font-weight: bold; font-size: 10px; }
    .b-bon { background: #e6f4ea; color: #1b7f3b; }
    .b-surveiller { background: #fff4d6; color: #8a5a00; }
    .b-critique { background: #fce8e6; color: #b3261e; }
    .kpi td { width: 25%; border: 1px solid #c9cdd6; padding: 7px 8px; vertical-align: top; }
    .kpi .v { font-size: 16px; font-weight: bold; }
    .kpi .l { color: #5b6272; font-size: 8.4px; }
    .up { color: #1b7f3b; } .down { color: #b3261e; }
    .grid th { background: #eef0f4; text-align: left; padding: 5px 6px; font-size: 8.6px; border: 1px solid #c9cdd6; }
    .grid td { padding: 4px 6px; border: 1px solid #dde0e6; font-size: 8.8px; }
    .grid td.n, .grid th.n { text-align: right; }
    .bar { background: #1f2430; height: 8px; }
    .barbg { background: #eef0f4; height: 8px; }
    .alert { padding: 5px 8px; margin: 3px 0; border-left: 3px solid; }
    .al-critique { border-color: #b3261e; background: #fdf1f0; }
    .al-important { border-color: #c77700; background: #fff8e8; }
    .al-information { border-color: #5b6272; background: #f4f5f7; }
    .ok { border-left: 3px solid #1b7f3b; background: #f1f8f3; padding: 5px 8px; margin: 3px 0; }
    .reco { border-left: 3px solid #1f2430; background: #f6f7f9; padding: 5px 8px; margin: 3px 0; }
    .page-break { page-break-before: always; }
    .footer { position: fixed; bottom: -38px; left: 0; right: 0; font-size: 8px; color: #5b6272; border-top: 1px solid #c9cdd6; padding-top: 4px; }
</style>
</head>
<body>
@php
    $o = $d['overview'];
    $fmt = fn ($v, $suffix = '') => $v === null ? '—' : (is_float($v) ? number_format($v, 1, ',', ' ') : number_format((int) $v, 0, ',', ' ')) . $suffix;
    $delta = function ($k) use ($d) {
        $c = $d['comparison'][$k]['change_pct'] ?? null;
        if ($c === null) return '<span class="muted">—</span>';
        return '<span class="' . ($c >= 0 ? 'up' : 'down') . '">' . ($c >= 0 ? '+' : '') . number_format($c, 1, ',', ' ') . ' %</span>';
    };
    $levelClass = ['bon' => 'b-bon', 'à surveiller' => 'b-surveiller', 'critique' => 'b-critique'][$a['level']] ?? 'b-surveiller';
    $maxSeries = max(1, collect($d['evolution']['series'])->flatten(1)->max('value') ?? 1);
@endphp

<div class="footer">DocTrack — {{ $r->reference }} — {{ $d['meta']['scope']['name'] }} — {{ $r->period_label }} — généré le {{ \Illuminate\Support\Carbon::parse($d['meta']['generated_at'])->timezone('Africa/Dakar')->format('d/m/Y à H:i') }}{{ $r->automatic ? ' (automatique)' : '' }}</div>

<div class="cover">
    <div class="small muted">RÉPUBLIQUE DU SÉNÉGAL — Plateforme nationale DocTrack</div>
    <h1>Rapport d'activité</h1>
    <div style="font-size: 13px; font-weight: bold;">{{ $d['meta']['scope']['name'] }} — {{ $r->period_label }}</div>
    <div class="muted" style="margin-top: 4px;">Référence {{ $r->reference }} · Période du {{ \Illuminate\Support\Carbon::parse($d['meta']['period']['from'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($d['meta']['period']['to'])->format('d/m/Y') }} · Comparaison avec la période précédente de même durée</div>
    <div style="margin-top: 8px;">Niveau global : <span class="badge {{ $levelClass }}">{{ ucfirst($a['level']) }} — {{ $a['score'] }}/100</span>
        @if ($d['meta']['billing_mode'] !== 'live') <span class="small muted">· Facturation en phase pilote (gratuit)</span> @endif</div>
</div>

<h2>1. Synthèse</h2>
@if (! empty($n))
    <p>{{ $n['summary_fr'] }}</p>
    @foreach ($n['highlights'] ?? [] as $h)<div class="reco">{{ $h }}</div>@endforeach
    <p class="small muted">Synthèse rédigée avec l'aide de l'IA à partir des seuls chiffres agrégés de ce rapport ; chaque chiffre a été vérifié automatiquement ; texte validé avant publication.</p>
@else
    <p>{{ $a['summary'] }}</p>
@endif

<table class="kpi">
    <tr>
        <td><div class="v">{{ $fmt($o['declarations']['counter']) }}</div><div class="l">Déclarations au guichet</div>{!! $delta('counter_declarations') !!}</td>
        <td><div class="v">{{ $fmt($o['found_documents']['entered_custody']) }}</div><div class="l">Documents trouvés entrés sous garde</div></td>
        <td><div class="v">{{ $fmt($o['matches']['created']) }}</div><div class="l">Correspondances détectées</div>{!! $delta('matches_created') !!}</td>
        <td><div class="v">{{ $fmt($o['restitutions']['completed']) }}</div><div class="l">Restitutions officielles</div>{!! $delta('restitutions') !!}</td>
    </tr>
    <tr>
        <td><div class="v">{{ $fmt($o['collections']['assigned']) }}</div><div class="l">Collectes auprès de trouveurs</div>{!! $delta('collections_assigned') !!}</td>
        <td><div class="v">{{ $fmt($o['collections']['median_first_contact_hours'], ' h') }}</div><div class="l">1er appel au trouveur (médiane)</div></td>
        <td><div class="v">{{ $fmt($o['collections']['contacted_within_sla_pct'], ' %') }}</div><div class="l">Trouveurs appelés sous {{ $d['meta']['sla']['first_contact_hours'] }} h</div></td>
        <td><div class="v">{{ $fmt($o['found_documents']['restitution_rate_pct'], ' %') }}</div><div class="l">Taux de restitution des entrées</div></td>
    </tr>
</table>

<h2>2. Analyse et recommandations</h2>
@forelse ($a['alerts'] as $al)
    <div class="alert al-{{ $al['severity'] }}"><strong>{{ ucfirst($al['severity']) }}</strong> — {{ $al['message'] }}</div>
@empty
    <div class="ok">Aucune alerte sur la période.</div>
@endforelse
@foreach ($a['positives'] as $p)<div class="ok">{{ $p }}</div>@endforeach
@if (! empty($n['recommendations']))
    <h3>Recommandations</h3>
    @foreach ($n['recommendations'] as $i => $reco)<div class="reco">{{ $i + 1 }}. <strong>[{{ $reco['priority'] }}]</strong> {{ $reco['text'] }}</div>@endforeach
@elseif ($a['recommendations'])
    <h3>Recommandations</h3>
    @foreach ($a['recommendations'] as $i => $reco)<div class="reco">{{ $i + 1 }}. {{ $reco }}</div>@endforeach
@endif
@if (! empty($n['structures']))
    <h3>Commentaires par structure</h3>
    @foreach ($n['structures'] as $st)<div class="reco"><strong>{{ $st['name'] }}</strong> — {{ $st['comment'] }}</div>@endforeach
@endif

<h2>3. Évolution</h2>
<table class="grid">
    <tr><th>Période</th><th>Déclarations</th><th>Dépôts</th><th>Collectes</th><th>Restitutions</th><th style="width: 34%;">Déclarations (barre)</th></tr>
    @foreach ($d['evolution']['series']['counter_declarations'] as $i => $p)
        <tr>
            <td>{{ $p['period'] }}</td>
            <td class="n">{{ $p['value'] }}</td>
            <td class="n">{{ $d['evolution']['series']['deposits'][$i]['value'] ?? 0 }}</td>
            <td class="n">{{ $d['evolution']['series']['collections_assigned'][$i]['value'] ?? 0 }}</td>
            <td class="n">{{ $d['evolution']['series']['restitutions'][$i]['value'] ?? 0 }}</td>
            <td>@if ($p['value'] > 0)<table><tr><td style="padding:0; border:0; width: {{ max(2, round($p['value'] / $maxSeries * 100)) }}%;"><div class="bar"></div></td><td style="padding:0; border:0;"><div class="barbg"></div></td></tr></table>@else<div class="barbg"></div>@endif</td>
        </tr>
    @endforeach
</table>

<div class="page-break"></div>
<h2>4. Délais de réaction des structures</h2>
<p class="muted small">Délai attendu : premier appel au trouveur sous {{ $d['meta']['sla']['first_contact_hours'] }} h, alerte hiérarchique à {{ $d['meta']['sla']['escalate_hours'] }} h. Les structures en retard apparaissent en premier.</p>
<table class="grid">
    <tr><th>Structure</th><th class="n">Collectes</th><th class="n">1er appel (h)</th><th class="n">Dans le délai</th><th class="n">En retard</th><th class="n">Récupérés</th><th class="n">Sous garde</th><th class="n">À vérifier</th><th class="n">Déclar.</th><th class="n">Restit.</th></tr>
    @forelse ($d['structures'] as $s)
        <tr>
            <td>{{ $s['name'] }}@if ($s['parent'])<br><span class="small muted">{{ $s['parent'] }}</span>@endif</td>
            <td class="n">{{ $s['collections_assigned'] }}</td>
            <td class="n">{{ $fmt($s['median_first_contact_hours']) }}</td>
            <td class="n">{{ $fmt($s['contacted_within_sla_pct'], ' %') }}</td>
            <td class="n" style="{{ $s['collections_overdue_now'] ? 'color:#b3261e;font-weight:bold;' : '' }}">{{ $s['collections_overdue_now'] }}</td>
            <td class="n">{{ $s['collected'] }}</td>
            <td class="n">{{ $s['in_custody_now'] }}</td>
            <td class="n">{{ $s['pending_reviews_now'] }}</td>
            <td class="n">{{ $s['counter_declarations'] }}</td>
            <td class="n">{{ $s['restitutions'] }}</td>
        </tr>
    @empty
        <tr><td colspan="10" class="muted">Aucune structure dans ce périmètre.</td></tr>
    @endforelse
</table>

@if ($d['overdue_collections'])
    <h3>Collectes en attente d'un premier appel</h3>
    <table class="grid">
        <tr><th>Structure</th><th>Référence</th><th>Document</th><th>Lieu</th><th class="n">Attente</th><th>Priorité</th></tr>
        @foreach ($d['overdue_collections'] as $c)
            <tr><td>{{ $c['structure']['name'] ?? '' }}</td><td>{{ $c['reference'] }}</td><td>{{ $c['document_type'] }}</td><td>{{ $c['place'] }}</td>
                <td class="n">{{ $c['hours_waiting'] }} h</td><td>{{ $c['priority'] ? 'Propriétaire identifié' : '' }}{{ $c['escalated'] ? ' · alerté' : '' }}</td></tr>
        @endforeach
    </table>
@endif

<h2>5. Documents trouvés, correspondances et restitutions</h2>
<table class="grid">
    <tr><th>Indicateur</th><th class="n">Valeur</th><th>Indicateur</th><th class="n">Valeur</th></tr>
    <tr><td>Déposés au guichet</td><td class="n">{{ $o['found_documents']['deposited_at_counter'] }}</td><td>Correspondances auto-confirmées (numéro)</td><td class="n">{{ $o['matches']['auto_confirmed'] }}</td></tr>
    <tr><td>Récupérés chez des trouveurs</td><td class="n">{{ $o['found_documents']['collected_from_finders'] }}</td><td>Confirmées par un agent</td><td class="n">{{ $o['matches']['confirmed_by_agents'] }}</td></tr>
    <tr><td>Sous garde aujourd'hui</td><td class="n">{{ $o['found_documents']['in_custody_now'] }}</td><td>Rejetées</td><td class="n">{{ $o['matches']['rejected'] }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont moins de 30 jours</td><td class="n">{{ $o['found_documents']['custody_age']['under_30_days'] }}</td><td>À vérifier aujourd'hui</td><td class="n">{{ $o['matches']['pending_review_now'] }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont 30 à 90 jours</td><td class="n">{{ $o['found_documents']['custody_age']['30_to_90_days'] }}</td><td>Délai médian de vérification</td><td class="n">{{ $fmt($o['matches']['median_review_hours'], ' h') }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont plus de 90 jours</td><td class="n">{{ $o['found_documents']['custody_age']['over_90_days'] }}</td><td>Restitutions par procuration</td><td class="n">{{ $o['restitutions']['by_proxy'] }}</td></tr>
    <tr><td>Restitutions annulées</td><td class="n">{{ $o['restitutions']['voided'] }}</td><td>Sans déclaration préalable</td><td class="n">{{ $o['restitutions']['without_prior_declaration'] }}</td></tr>
    <tr><td>Durée médiane sous garde</td><td class="n">{{ $fmt($o['restitutions']['median_days_in_custody'], ' j') }}</td><td>Dérogations sur le nom</td><td class="n">{{ $o['restitutions']['name_derogations'] }}</td></tr>
</table>

@if ($d['direct_restitutions'])
    <h3>Remises directes entre citoyens (double accord)</h3>
    <p>{{ $d['direct_restitutions']['requests'] }} demande(s) — taux d'acceptation par les trouveurs : {{ $fmt($d['direct_restitutions']['acceptance_pct'], ' %') }}.
       @foreach ($d['direct_restitutions']['by_status'] as $st => $n) {{ $st }} : {{ $n }}@if (! $loop->last), @endif @endforeach</p>
@endif
@if ($d['qr'])
    <h3>QR codes par document</h3>
    <p>{{ $d['qr']['tags_created'] }} QR créé(s) sur la période ({{ $d['qr']['tags_active_total'] }} actifs au total) — {{ $d['qr']['scans'] }} scan(s) par des trouveurs, dont {{ $d['qr']['scans_with_collection'] }} avec accord de collecte.</p>
@endif

<div class="page-break"></div>
<h2>6. Répartition par type de document</h2>
<table class="grid">
    <tr><th>Type</th><th class="n">Déclarés</th><th class="n">Trouvés</th><th class="n">Restitués</th></tr>
    @forelse ($d['by_document_type'] as $t)
        <tr><td>{{ $t['type'] }}</td><td class="n">{{ $t['declared'] }}</td><td class="n">{{ $t['found'] }}</td><td class="n">{{ $t['restituted'] }}</td></tr>
    @empty <tr><td colspan="4" class="muted">Aucune donnée.</td></tr> @endforelse
</table>

<h2>7. Répartition par région</h2>
<table class="grid">
    <tr><th>Région</th><th class="n">Déclarations</th><th class="n">Documents trouvés</th><th class="n">Restitutions</th></tr>
    @forelse ($d['by_region'] as $g)
        <tr><td>{{ $g['region'] }}</td><td class="n">{{ $g['declarations'] }}</td><td class="n">{{ $g['found'] }}</td><td class="n">{{ $g['restitutions'] }}</td></tr>
    @empty <tr><td colspan="4" class="muted">Aucune donnée.</td></tr> @endforelse
</table>

<h2>8. Finances</h2>
@php $bt = $d['billing']['totals']; @endphp
<p>Mode de facturation : <strong>{{ $d['billing']['mode'] === 'live' ? 'tarifs appliqués' : 'phase pilote (gratuit, valeur théorique tracée)' }}</strong>.</p>
<table class="grid">
    <tr><th>Service</th><th class="n">Démarches</th><th class="n">Perçu</th><th class="n">Part autorités</th><th class="n">Part DocTrack</th><th class="n">Exonérations</th><th class="n">Valeur théorique pilote</th></tr>
    @foreach ($d['billing']['by_service'] as $s)
        <tr><td>{{ $s['name'] }}</td><td class="n">{{ $s['count'] }}</td><td class="n">{{ $fmt($s['collected']) }}</td><td class="n">{{ $fmt($s['authority_share']) }}</td><td class="n">{{ $fmt($s['platform_share']) }}</td><td class="n">{{ $s['exempted_count'] }}</td><td class="n">{{ $fmt($s['pilot_theoretical_value']) }}</td></tr>
    @endforeach
    <tr><th>Total (FCFA)</th><th class="n">{{ $bt['count'] }}</th><th class="n">{{ $fmt($bt['collected']) }}</th><th class="n">{{ $fmt($bt['authority_share']) }}</th><th class="n">{{ $fmt($bt['platform_share']) }}</th><th class="n">{{ $bt['exempted_count'] }}</th><th class="n">{{ $fmt($bt['pilot_theoretical_value']) }}</th></tr>
</table>

<h2>9. Notifications</h2>
<p>SMS envoyés : {{ $o['sms']['sent'] }} — en échec : {{ $o['sms']['failed'] }} — non envoyés faute de numéro : {{ $o['sms']['skipped'] }}.</p>

@if (! empty($n['summary_en']))
<h2>Executive summary (English)</h2>
<p>{{ $n['summary_en'] }}</p>
@endif

<h2>Méthodologie</h2>
<p class="small muted">Chiffres calculés automatiquement à partir des données enregistrées dans DocTrack pour le périmètre « {{ $d['meta']['scope']['name'] }} » (l'organisation et toutes ses structures rattachées). Les médianes sont exprimées en heures ou en jours. La comparaison porte sur la période précédente de même durée ({{ \Illuminate\Support\Carbon::parse($d['meta']['previous']['from'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($d['meta']['previous']['to'])->format('d/m/Y') }}). Les indicateurs « aujourd'hui » reflètent la situation au moment de la génération. L'analyse applique des règles explicites (seuils publiés) : aucune donnée personnelle n'apparaît dans ce rapport.@if (! empty($n)) La synthèse, les commentaires et les recommandations ont été rédigés par un modèle de langage ({{ $n['model'] ?? '' }}) qui n'a reçu que des chiffres agrégés (effectifs inférieurs à {{ config('ai.reports.min_count', 3) }} masqués) ; tout chiffre absent des données entraîne le rejet du texte ; le texte a été relu et validé{{ ! empty($n['edited_by_reviewer']) ? ' (et corrigé)' : '' }} avant publication.@endif</p>
</body>
</html>

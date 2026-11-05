<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport d'activités — {{ $r->period_label }}</title>
<style>
    /* Charte DocTrack : violet #31287C, or #F6A500, lavande #F4F3FB (même identité que la plateforme). */
    @page { margin: 40px 44px 58px 44px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.8px; color: #1f1d33; line-height: 1.55; }
    table { width: 100%; border-collapse: collapse; }
    p { margin: 0 0 7px 0; text-align: justify; }
    strong { color: #1e1a5c; }
    .muted { color: #5b5878; }
    .small { font-size: 8.4px; }

    /* Page de garde */
    .brand { margin: 26px 0 0 0; text-align: center; }
    .brand .word { font-size: 26px; font-weight: bold; color: #31287C; letter-spacing: .5px; }
    .brand .word span { color: #F6A500; }
    .brand .tag { font-size: 8.6px; color: #5b5878; letter-spacing: 1.6px; text-transform: uppercase; margin-top: 2px; }
    .cover-title { margin: 26px 0 0 0; background: #31287C; color: #ffffff; padding: 24px 16px 20px 16px; text-align: center;
        border-bottom: 5px solid #F6A500; }
    .cover-title .t1 { font-size: 23px; font-weight: bold; letter-spacing: 3px; color: #ffffff; }
    .cover-title .t2 { font-size: 12.5px; font-weight: bold; margin-top: 8px; text-transform: uppercase; color: #F6A500; letter-spacing: 1px; }
    .cover-title .t3 { font-size: 10.5px; margin-top: 6px; color: #e4e1f7; }
    .cover-meta { margin: 30px auto 0 auto; width: 78%; }
    .cover-meta td { padding: 7px 10px; border-bottom: 1px solid #e8e6f5; font-size: 9.8px; }
    .cover-meta td.k { width: 42%; background: #F4F3FB; font-weight: bold; color: #31287C; border-left: 3px solid #F6A500; }
    .level { font-weight: bold; }
    .lv-bon { color: #15803d; } .lv-surveiller { color: #a16207; } .lv-critique { color: #c62828; }
    .cover-foot { position: absolute; bottom: 10px; left: 0; right: 0; text-align: center; font-size: 8.4px; color: #5b5878;
        border-top: 1px solid #e8e6f5; padding-top: 6px; }

    /* Titres (parties, chapitres, sections) */
    .part { font-size: 12px; font-weight: bold; text-align: left; text-transform: uppercase; letter-spacing: 1px; margin: 4px 0 14px 0;
        padding: 8px 12px; background: #31287C; color: #ffffff; border-left: 6px solid #F6A500; }
    h2 { font-size: 11px; font-weight: bold; text-transform: uppercase; margin: 16px 0 7px 0; color: #31287C;
        padding: 0 0 3px 8px; border-left: 3px solid #F6A500; border-bottom: 1px solid #e8e6f5; }
    h3 { font-size: 10px; font-weight: bold; margin: 11px 0 5px 0; color: #1e1a5c; }

    /* Tableaux numérotés avec titre au-dessus et source en dessous */
    .cap { font-size: 9.2px; font-weight: bold; margin: 10px 0 4px 0; color: #31287C; }
    .grid th { background: #31287C; color: #ffffff; text-align: left; padding: 6px 7px; font-size: 8.4px; border: 1px solid #31287C;
        text-transform: uppercase; letter-spacing: .3px; }
    .grid td { padding: 5px 7px; border-bottom: 1px solid #e8e6f5; border-left: 0; border-right: 0; font-size: 8.8px; }
    .grid tr:nth-child(even) td { background: #faf9ff; }
    .grid td.n, .grid th.n { text-align: right; }
    .grid tr.total td { font-weight: bold; background: #f0eeff; color: #1e1a5c; border-top: 2px solid #31287C; }
    .src { font-size: 7.6px; color: #5b5878; font-style: italic; margin: 3px 0 9px 0; }

    .toc td { padding: 4px 0 4px 6px; font-size: 9.8px; border-bottom: 1px dotted #d9d5ee; }
    .toc td.p { text-align: right; width: 30px; }
    .bullet { margin: 0 0 5px 12px; padding-left: 8px; border-left: 2px solid #F6A500; }
    .up { color: #15803d; } .down { color: #c62828; }

    .sign { margin-top: 26px; }
    .sign td { vertical-align: top; }
    .page-break { page-break-before: always; }

    /* Pied de page courant */
    .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.6px; color: #5b5878; border-top: 2px solid #31287C; padding-top: 4px; }
    .footer .doc { color: #31287C; font-weight: bold; }
    .footer .doc span { color: #F6A500; }
    .pagenum:before { content: counter(page); }
</style>
</head>
<body>
@php
    $o = $d['overview'];
    $scope = $d['meta']['scope']['name'];
    $fmt = fn ($v, $suffix = '') => $v === null ? '—' : (is_float($v) ? number_format($v, 1, ',', ' ') : number_format((int) $v, 0, ',', ' ')) . $suffix;
    $delta = function ($k) use ($d) {
        $c = $d['comparison'][$k]['change_pct'] ?? null;
        if ($c === null) return '<span class="muted">—</span>';
        return '<span class="' . ($c >= 0 ? 'up' : 'down') . '">' . ($c >= 0 ? '+' : '') . number_format($c, 1, ',', ' ') . ' %</span>';
    };
    $from = \Illuminate\Support\Carbon::parse($d['meta']['period']['from']);
    $to   = \Illuminate\Support\Carbon::parse($d['meta']['period']['to']);
    $generated = \Illuminate\Support\Carbon::parse($d['meta']['generated_at'])->timezone('Africa/Dakar');
    $levelClass = ['bon' => 'lv-bon', 'à surveiller' => 'lv-surveiller', 'critique' => 'lv-critique'][$a['level']] ?? 'lv-surveiller';
    $source = 'Source : plateforme DocTrack, données au ' . $generated->format('d/m/Y');
    $official = $lh['official'] ?? false;
    $recos = ! empty($n['recommendations']) ? collect($n['recommendations'])->map(fn ($x) => '[' . $x['priority'] . '] ' . $x['text'])->all() : ($a['recommendations'] ?? []);
    $tableNo = 0;
    $cap = function (string $title) use (&$tableNo) { $tableNo++; return 'Tableau n° ' . $tableNo . ' : ' . $title; };
@endphp

{{-- ═════════════ Page de garde ═════════════ --}}
{{-- Pied de page (numéro de page) : déclaré ici pour figurer sur toutes les pages. --}}
<div class="footer">
    <table><tr>
        <td><span class="doc">Doc<span>Track</span></span> · Rapport d'activités — {{ $r->period_label }} — {{ $scope }} — réf. {{ $r->reference }}</td>
        <td style="text-align: right; width: 60px;">Page <span class="pagenum"></span></td>
    </tr></table>
</div>
@include('pdf.entete', [
    'lh'       => $lh,
    'etat'     => $official,
    'numero'   => 'N° ' . $r->reference,
    'lieuDate' => $lh['city'] . ', le ' . \App\Support\Letterhead::longDate($generated),
])

@unless ($official)
    <div class="brand">
        <div class="word">Doc<span>Track</span></div>
        <div class="tag">Plateforme nationale des documents perdus et retrouvés</div>
    </div>
@endunless

<div class="cover-title">
    <div class="t1">RAPPORT D'ACTIVITÉS</div>
    <div class="t2">{{ $r->period_label }}</div>
    <div class="t3">{{ $scope }}</div>
</div>

<table class="cover-meta">
    <tr><td class="k">Période couverte</td><td>du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Périmètre</td><td>{{ $scope }} (structures rattachées comprises)</td></tr>
    <tr><td class="k">Référence</td><td>{{ $r->reference }}</td></tr>
    <tr><td class="k">Appréciation d'ensemble</td><td class="level {{ $levelClass }}">{{ ucfirst($a['level']) }} — indice {{ $a['score'] }}/100</td></tr>
    <tr><td class="k">Établi le</td><td>{{ \App\Support\Letterhead::longDate($generated) }}{{ $r->automatic ? ' (génération automatique)' : '' }}</td></tr>
    @if (! empty($n) && $reviewer)
        <tr><td class="k">Synthèse validée par</td><td>{{ $reviewer->FirstName }} {{ $reviewer->LastName }}, le {{ $r->narrative_reviewed_at?->timezone('Africa/Dakar')->format('d/m/Y') }}</td></tr>
    @endif
</table>

<div class="cover-foot">
    {{ $official ? 'Document de service — diffusion restreinte' : 'Plateforme nationale DocTrack — diffusion restreinte aux institutions partenaires' }}<br>
    Aucune donnée personnelle ne figure dans ce rapport.
</div>

{{-- ═════════════ Pages intérieures ═════════════ --}}
<div class="page-break"></div>


<div class="part">Sommaire</div>
<table class="toc">
    <tr><td>Sigles et abréviations</td></tr>
    <tr><td>Introduction</td></tr>
    <tr><td><strong>Première partie : bilan des activités</strong></td></tr>
    <tr><td>&nbsp;&nbsp;&nbsp;I. Chiffres clés de la période</td></tr>
    <tr><td>&nbsp;&nbsp;&nbsp;II. Évolution de l'activité</td></tr>
    <tr><td>&nbsp;&nbsp;&nbsp;III. Délais de traitement par structure</td></tr>
    <tr><td>&nbsp;&nbsp;&nbsp;IV. Documents trouvés, correspondances et restitutions</td></tr>
    <tr><td>&nbsp;&nbsp;&nbsp;V. Répartition par type de document et par région</td></tr>
    <tr><td><strong>Deuxième partie : analyse, difficultés et recommandations</strong></td></tr>
    <tr><td><strong>Troisième partie : situation financière et notifications</strong></td></tr>
    <tr><td>Conclusion</td></tr>
    <tr><td>Annexe : méthodologie{{ ! empty($n['summary_en']) ? ' et résumé en anglais' : '' }}</td></tr>
</table>

<h2 style="margin-top: 20px;">Sigles et abréviations</h2>
<table class="grid">
    <tr><td style="width: 18%;"><strong>CP</strong></td><td>Certificat de perte</td></tr>
    <tr><td><strong>DT</strong></td><td>Document trouvé (référence DocTrack)</td></tr>
    <tr><td><strong>IA</strong></td><td>Intelligence artificielle</td></tr>
    <tr><td><strong>QR</strong></td><td>Code « Quick Response » apposé sur un document</td></tr>
    <tr><td><strong>RS</strong></td><td>Décharge de restitution</td></tr>
    <tr><td><strong>SMS</strong></td><td>Message court envoyé par téléphone</td></tr>
</table>

<h2>Introduction</h2>
<p>
    Le présent rapport rend compte de l'activité de la plateforme DocTrack pour le périmètre « {{ $scope }} »
    au cours de la période du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}. Il présente successivement
    le bilan chiffré des déclarations de perte, des documents trouvés, des correspondances et des restitutions ;
    l'analyse des résultats, des difficultés et les recommandations ; enfin la situation financière et les notifications.
    Les variations sont calculées par rapport à la période précédente de même durée.
</p>
@if (! empty($n))
    <p>{{ $n['summary_fr'] }}</p>
@else
    <p>{{ $a['summary'] }}</p>
@endif

{{-- ── PREMIÈRE PARTIE ── --}}
<div class="page-break"></div>
<div class="part">Première partie : bilan des activités</div>

<h2>I. Chiffres clés de la période</h2>
<div class="cap">{{ $cap('Principaux indicateurs') }}</div>
<table class="grid">
    <tr><th>Indicateur</th><th class="n">Valeur</th><th class="n">Variation</th></tr>
    <tr><td>Déclarations de perte reçues au guichet</td><td class="n">{{ $fmt($o['declarations']['counter']) }}</td><td class="n">{!! $delta('counter_declarations') !!}</td></tr>
    <tr><td>Documents trouvés entrés sous garde</td><td class="n">{{ $fmt($o['found_documents']['entered_custody']) }}</td><td class="n">—</td></tr>
    <tr><td>Correspondances détectées</td><td class="n">{{ $fmt($o['matches']['created']) }}</td><td class="n">{!! $delta('matches_created') !!}</td></tr>
    <tr><td>Restitutions officielles</td><td class="n">{{ $fmt($o['restitutions']['completed']) }}</td><td class="n">{!! $delta('restitutions') !!}</td></tr>
    <tr><td>Collectes auprès de trouveurs</td><td class="n">{{ $fmt($o['collections']['assigned']) }}</td><td class="n">{!! $delta('collections_assigned') !!}</td></tr>
    <tr><td>Délai médian du premier appel au trouveur</td><td class="n">{{ $fmt($o['collections']['median_first_contact_hours'], ' h') }}</td><td class="n">—</td></tr>
    <tr><td>Trouveurs appelés dans le délai de {{ $d['meta']['sla']['first_contact_hours'] }} h</td><td class="n">{{ $fmt($o['collections']['contacted_within_sla_pct'], ' %') }}</td><td class="n">—</td></tr>
    <tr><td>Taux de restitution des documents entrés sous garde</td><td class="n">{{ $fmt($o['found_documents']['restitution_rate_pct'], ' %') }}</td><td class="n">—</td></tr>
</table>
<div class="src">{{ $source }}</div>

<h2>II. Évolution de l'activité</h2>
<div class="cap">{{ $cap('Évolution par sous-période') }}</div>
<table class="grid">
    <tr><th>Sous-période</th><th class="n">Déclarations</th><th class="n">Dépôts</th><th class="n">Collectes</th><th class="n">Restitutions</th></tr>
    @foreach ($d['evolution']['series']['counter_declarations'] as $i => $p)
        <tr>
            <td>{{ $p['period'] }}</td>
            <td class="n">{{ $p['value'] }}</td>
            <td class="n">{{ $d['evolution']['series']['deposits'][$i]['value'] ?? 0 }}</td>
            <td class="n">{{ $d['evolution']['series']['collections_assigned'][$i]['value'] ?? 0 }}</td>
            <td class="n">{{ $d['evolution']['series']['restitutions'][$i]['value'] ?? 0 }}</td>
        </tr>
    @endforeach
</table>
<div class="src">{{ $source }}</div>

<h2>III. Délais de traitement par structure</h2>
<p>Délai attendu : premier appel au trouveur sous {{ $d['meta']['sla']['first_contact_hours'] }} heures, alerte de la hiérarchie à {{ $d['meta']['sla']['escalate_hours'] }} heures. Les structures présentant des retards figurent en premier.</p>
<div class="cap">{{ $cap('Délais et activité par structure') }}</div>
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
<div class="src">{{ $source }}</div>

@if ($d['overdue_collections'])
    <div class="cap">{{ $cap('Collectes en attente d\'un premier appel') }}</div>
    <table class="grid">
        <tr><th>Structure</th><th>Référence</th><th>Document</th><th>Lieu</th><th class="n">Attente</th><th>Observations</th></tr>
        @foreach ($d['overdue_collections'] as $c)
            <tr><td>{{ $c['structure']['name'] ?? '' }}</td><td>{{ $c['reference'] }}</td><td>{{ $c['document_type'] }}</td><td>{{ $c['place'] }}</td>
                <td class="n">{{ $c['hours_waiting'] }} h</td><td>{{ $c['priority'] ? 'Propriétaire identifié' : '' }}{{ $c['escalated'] ? ' · hiérarchie alertée' : '' }}</td></tr>
        @endforeach
    </table>
    <div class="src">{{ $source }}</div>
@endif

<h2>IV. Documents trouvés, correspondances et restitutions</h2>
<div class="cap">{{ $cap('Documents trouvés, correspondances et restitutions') }}</div>
<table class="grid">
    <tr><th>Documents trouvés</th><th class="n">Nombre</th><th>Correspondances et restitutions</th><th class="n">Nombre</th></tr>
    <tr><td>Déposés au guichet</td><td class="n">{{ $o['found_documents']['deposited_at_counter'] }}</td><td>Correspondances confirmées automatiquement (numéro)</td><td class="n">{{ $o['matches']['auto_confirmed'] }}</td></tr>
    <tr><td>Récupérés chez des trouveurs</td><td class="n">{{ $o['found_documents']['collected_from_finders'] }}</td><td>Correspondances confirmées par un agent</td><td class="n">{{ $o['matches']['confirmed_by_agents'] }}</td></tr>
    <tr><td>Sous garde à ce jour</td><td class="n">{{ $o['found_documents']['in_custody_now'] }}</td><td>Correspondances rejetées</td><td class="n">{{ $o['matches']['rejected'] }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont moins de 30 jours</td><td class="n">{{ $o['found_documents']['custody_age']['under_30_days'] }}</td><td>Correspondances à vérifier à ce jour</td><td class="n">{{ $o['matches']['pending_review_now'] }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont 30 à 90 jours</td><td class="n">{{ $o['found_documents']['custody_age']['30_to_90_days'] }}</td><td>Délai médian de vérification</td><td class="n">{{ $fmt($o['matches']['median_review_hours'], ' h') }}</td></tr>
    <tr><td>&nbsp;&nbsp;dont plus de 90 jours</td><td class="n">{{ $o['found_documents']['custody_age']['over_90_days'] }}</td><td>Restitutions par procuration</td><td class="n">{{ $o['restitutions']['by_proxy'] }}</td></tr>
    <tr><td>Restitutions annulées</td><td class="n">{{ $o['restitutions']['voided'] }}</td><td>Restitutions sans déclaration préalable</td><td class="n">{{ $o['restitutions']['without_prior_declaration'] }}</td></tr>
    <tr><td>Durée médiane sous garde</td><td class="n">{{ $fmt($o['restitutions']['median_days_in_custody'], ' j') }}</td><td>Dérogations accordées sur le nom</td><td class="n">{{ $o['restitutions']['name_derogations'] }}</td></tr>
</table>
<div class="src">{{ $source }}</div>

@if ($d['direct_restitutions'])
    <h3>Remises directes entre citoyens (double accord)</h3>
    <p>{{ $d['direct_restitutions']['requests'] }} demande(s) enregistrée(s) ; taux d'acceptation par les trouveurs : {{ $fmt($d['direct_restitutions']['acceptance_pct'], ' %') }}.
       @foreach ($d['direct_restitutions']['by_status'] as $st => $nb) {{ $st }} : {{ $nb }}@if (! $loop->last), @endif @endforeach</p>
@endif
@if ($d['qr'])
    <h3>QR codes apposés sur les documents</h3>
    <p>{{ $d['qr']['tags_created'] }} QR code(s) créé(s) sur la période ({{ $d['qr']['tags_active_total'] }} actifs au total) ; {{ $d['qr']['scans'] }} scan(s) par des trouveurs, dont {{ $d['qr']['scans_with_collection'] }} avec accord de collecte.</p>
@endif

<h2>V. Répartition par type de document et par région</h2>
<div class="cap">{{ $cap('Répartition par type de document') }}</div>
<table class="grid">
    <tr><th>Type de document</th><th class="n">Déclarés perdus</th><th class="n">Trouvés</th><th class="n">Restitués</th></tr>
    @forelse ($d['by_document_type'] as $t)
        <tr><td>{{ $t['type'] }}</td><td class="n">{{ $t['declared'] }}</td><td class="n">{{ $t['found'] }}</td><td class="n">{{ $t['restituted'] }}</td></tr>
    @empty <tr><td colspan="4" class="muted">Aucune donnée.</td></tr> @endforelse
</table>
<div class="src">{{ $source }}</div>

<div class="cap">{{ $cap('Répartition par région') }}</div>
<table class="grid">
    <tr><th>Région</th><th class="n">Déclarations</th><th class="n">Documents trouvés</th><th class="n">Restitutions</th></tr>
    @forelse ($d['by_region'] as $g)
        <tr><td>{{ $g['region'] }}</td><td class="n">{{ $g['declarations'] }}</td><td class="n">{{ $g['found'] }}</td><td class="n">{{ $g['restitutions'] }}</td></tr>
    @empty <tr><td colspan="4" class="muted">Aucune donnée.</td></tr> @endforelse
</table>
<div class="src">{{ $source }}</div>

{{-- ── DEUXIÈME PARTIE ── --}}
<div class="page-break"></div>
<div class="part">Deuxième partie : analyse, difficultés et recommandations</div>

<h2>I. Acquis et points forts</h2>
@forelse ($a['positives'] as $p)
    <div class="bullet">– {{ $p }}</div>
@empty
    <p class="muted">Aucun point fort particulier relevé sur la période.</p>
@endforelse
@foreach ($n['highlights'] ?? [] as $h)<div class="bullet">– {{ $h }}</div>@endforeach

<h2>II. Difficultés et points de vigilance</h2>
@forelse ($a['alerts'] as $al)
    <div class="bullet">– <strong>{{ ucfirst($al['severity']) }} :</strong> {{ $al['message'] }}</div>
@empty
    <p>Aucune difficulté majeure n'a été relevée au cours de la période.</p>
@endforelse

<h2>III. Recommandations</h2>
@forelse ($recos as $i => $reco)
    <div class="bullet">{{ $i + 1 }}. {{ $reco }}</div>
@empty
    <p class="muted">Aucune recommandation particulière.</p>
@endforelse

@if (! empty($n['structures']))
    <h2>IV. Observations par structure</h2>
    @foreach ($n['structures'] as $st)<div class="bullet">– <strong>{{ $st['name'] }} :</strong> {{ $st['comment'] }}</div>@endforeach
@endif

{{-- ── TROISIÈME PARTIE ── --}}
<div class="part" style="margin-top: 22px;">Troisième partie : situation financière et notifications</div>

@php $bt = $d['billing']['totals']; @endphp
<h2>I. Situation financière</h2>
<p>Mode de facturation : <strong>{{ $d['billing']['mode'] === 'live' ? 'tarifs appliqués' : 'phase pilote (services gratuits, valeur théorique tracée)' }}</strong>. Montants exprimés en francs CFA.</p>
<div class="cap">{{ $cap('Recettes et répartition par service (FCFA)') }}</div>
<table class="grid">
    <tr><th>Service</th><th class="n">Démarches</th><th class="n">Perçu</th><th class="n">Part autorités</th><th class="n">Part DocTrack</th><th class="n">Exonérations</th><th class="n">Valeur pilote</th></tr>
    @foreach ($d['billing']['by_service'] as $s)
        <tr><td>{{ $s['name'] }}</td><td class="n">{{ $s['count'] }}</td><td class="n">{{ $fmt($s['collected']) }}</td><td class="n">{{ $fmt($s['authority_share']) }}</td><td class="n">{{ $fmt($s['platform_share']) }}</td><td class="n">{{ $s['exempted_count'] }}</td><td class="n">{{ $fmt($s['pilot_theoretical_value']) }}</td></tr>
    @endforeach
    <tr class="total"><td>Total</td><td class="n">{{ $bt['count'] }}</td><td class="n">{{ $fmt($bt['collected']) }}</td><td class="n">{{ $fmt($bt['authority_share']) }}</td><td class="n">{{ $fmt($bt['platform_share']) }}</td><td class="n">{{ $bt['exempted_count'] }}</td><td class="n">{{ $fmt($bt['pilot_theoretical_value']) }}</td></tr>
</table>
<div class="src">{{ $source }}</div>

<h2>II. Notifications aux usagers</h2>
<p>SMS envoyés : {{ $o['sms']['sent'] }} ; en échec : {{ $o['sms']['failed'] }} ; non envoyés faute de numéro : {{ $o['sms']['skipped'] }}.</p>

{{-- ── CONCLUSION ── --}}
<h2 style="margin-top: 20px;">Conclusion</h2>
<p>
    Au terme de la période du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}, l'activité du périmètre « {{ $scope }} »
    est jugée <strong>{{ ['bon' => 'satisfaisante', 'à surveiller' => 'à surveiller', 'critique' => 'critique'][$a['level']] ?? $a['level'] }}</strong> (indice {{ $a['score'] }}/100) :
    {{ $fmt($o['declarations']['counter']) }} déclaration(s) de perte au guichet, {{ $fmt($o['matches']['created']) }} correspondance(s)
    et {{ $fmt($o['restitutions']['completed']) }} restitution(s) officielle(s).
    @if (count($recos))
        Les efforts devront porter en priorité sur : {{ \Illuminate\Support\Str::lcfirst(preg_replace('/^\[[^\]]+\]\s*/', '', $recos[0])) }}
    @endif
</p>

<table class="sign">
    <tr>
        <td style="width: 50%;"></td>
        <td style="width: 50%; text-align: center;">
            Fait à {{ $lh['city'] }}, le {{ \App\Support\Letterhead::longDate($generated) }}<br><br>
            <strong>{{ $official ? $lh['signatory'] : 'Pour la plateforme DocTrack' }}</strong>
            @if (! empty($n) && $reviewer)<br><span class="small muted">Synthèse validée par {{ $reviewer->FirstName }} {{ $reviewer->LastName }}</span>@endif
        </td>
    </tr>
</table>

{{-- ── ANNEXE ── --}}
<div class="page-break"></div>
<div class="part">Annexe : méthodologie</div>
<p class="small">Chiffres calculés automatiquement à partir des données enregistrées dans DocTrack pour le périmètre « {{ $scope }} » (l'organisation et toutes ses structures rattachées). Les médianes sont exprimées en heures ou en jours. La comparaison porte sur la période précédente de même durée ({{ \Illuminate\Support\Carbon::parse($d['meta']['previous']['from'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($d['meta']['previous']['to'])->format('d/m/Y') }}). Les indicateurs « à ce jour » reflètent la situation au moment de l'établissement du rapport. L'appréciation d'ensemble applique des règles explicites (seuils publiés). Aucune donnée personnelle n'apparaît dans ce rapport.@if (! empty($n)) L'introduction, les points forts, les recommandations et les observations par structure ont été rédigés avec l'aide d'un modèle de langage ({{ $n['model'] ?? '' }}) qui n'a reçu que des chiffres agrégés (effectifs inférieurs à {{ config('ai.reports.min_count', 3) }} masqués) ; tout chiffre absent des données entraîne le rejet du texte ; le texte a été relu et validé{{ ! empty($n['edited_by_reviewer']) ? ' (et corrigé)' : '' }} avant diffusion.@endif</p>
@if ($d['meta']['billing_mode'] !== 'live')
    <p class="small">Facturation en phase pilote : aucun montant n'est perçu ; la valeur théorique des démarches est tracée pour préparer la tarification.</p>
@endif

@if (! empty($n['summary_en']))
    <h2>Executive summary (English)</h2>
    <p>{{ $n['summary_en'] }}</p>
@endif
</body>
</html>

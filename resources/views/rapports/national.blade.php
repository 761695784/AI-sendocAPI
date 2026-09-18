<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $meta['type'] }} DocTrack — {{ $stats['periode'] }}</title>
    <style>
        /* =====================================================================
           RESET & BASE — DocTrack Palette
           Primaire  : #31287C (violet institutionnel)
           Accent    : #F6A500 (orange DocTrack)
           Foncé     : #1e1a5c
           Surface   : #f4f3fb
           Vert      : #15803d
           Rouge     : #c62828
           Gris      : #64748b
        ===================================================================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 9.5pt;
            color: #1e1a3f;
            background: #ffffff;
            line-height: 1.55;
        }

        @page {
            margin: 0;
            size: A4 portrait;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            background: #ffffff;
            position: relative;
        }

        /* =====================================================================
           EN-TÊTE OFFICIEL
        ===================================================================== */
        .header {
            background: #31287C;
            position: relative;
            overflow: hidden;
        }

        /* Bande orange supérieure */
        .header-bande-top {
            height: 6px;
            background: #F6A500;
        }

        /* Motif décoratif (cercles subtils) */
        .header-deco-cercle {
            position: absolute;
            right: -30px;
            top: -30px;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: rgba(246,165,0,0.08);
            pointer-events: none;
        }

        .header-deco-cercle-2 {
            position: absolute;
            right: 60px;
            top: -50px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(246,165,0,0.05);
            pointer-events: none;
        }

        .header-content {
            padding: 20px 28px 18px;
            display: table;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .header-left  { display: table-cell; vertical-align: middle; width: 62%; }
        .header-right { display: table-cell; vertical-align: middle; text-align: right; width: 38%; }

        .header-republique {
            font-size: 6.5pt;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .header-pays {
            font-size: 8pt;
            color: #F6A500;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .header-logo-text {
            font-size: 26pt;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: 3px;
            line-height: 1;
        }

        .header-logo-text span { color: #F6A500; }

        .header-slogan {
            font-size: 7pt;
            color: rgba(255,255,255,0.5);
            margin-top: 5px;
            font-style: italic;
            letter-spacing: 0.3px;
        }

        /* Badge type rapport */
        .header-badge-type {
            display: inline-block;
            background: #F6A500;
            color: #31287C;
            font-size: 7pt;
            font-weight: bold;
            padding: 3px 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            border-radius: 2px;
            margin-bottom: 8px;
        }

        .header-ref {
            font-size: 6.5pt;
            color: rgba(255,255,255,0.45);
            margin-bottom: 2px;
            letter-spacing: 0.3px;
        }

        .header-date {
            font-size: 7pt;
            color: rgba(255,255,255,0.6);
        }

        /* Barre dégradée basse */
        .header-bande-bas {
            height: 3px;
            background: linear-gradient(90deg, #F6A500 0%, #31287C 60%);
        }

        /* =====================================================================
           BANDEAU PÉRIODE
        ===================================================================== */
        .bandeau-periode {
            background: #1e1a5c;
            padding: 11px 28px;
            display: table;
            width: 100%;
        }

        .bandeau-left  { display: table-cell; vertical-align: middle; }
        .bandeau-right { display: table-cell; vertical-align: middle; text-align: right; }

        .bandeau-label {
            font-size: 6.5pt;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 2px;
        }

        .bandeau-periode-nom {
            font-size: 13pt;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: 0.3px;
        }

        .bandeau-niveau {
            display: inline-block;
            padding: 5px 16px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .niveau-satisfaisant { background: #15803d; color: #fff; }
        .niveau-acceptable   { background: #ca8a04; color: #fff; }
        .niveau-preoccupant  { background: #ea580c; color: #fff; }
        .niveau-critique     { background: #c62828; color: #fff; }

        /* =====================================================================
           CORPS
        ===================================================================== */
        .body-content { padding: 22px 28px 40px; }

        /* =====================================================================
           SECTIONS
        ===================================================================== */
        .section { margin-bottom: 20px; }

        .section-titre {
            font-size: 8.5pt;
            font-weight: bold;
            color: #31287C;
            text-transform: uppercase;
            letter-spacing: 1.8px;
            border-bottom: 2.5px solid #31287C;
            padding-bottom: 5px;
            margin-bottom: 13px;
            display: table;
            width: 100%;
        }

        .section-titre::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 4px;
            background: #F6A500;
            border-radius: 50%;
            margin-right: 7px;
            vertical-align: middle;
        }

        .section-titre-accent { display: table-cell; }

        .section-titre-numero {
            display: table-cell;
            text-align: right;
            font-size: 7pt;
            color: #c4b8f5;
            font-weight: normal;
            letter-spacing: 0;
        }

        /* =====================================================================
           KPI CARDS
        ===================================================================== */
        .kpi-grid {
            display: table;
            width: 100%;
            border-collapse: separate;
            border-spacing: 7px;
        }

        .kpi-row  { display: table-row; }
        .kpi-cell { display: table-cell; width: 25%; }

        .kpi-card {
            background: #f4f3fb;
            border: 1px solid #e8e6f5;
            border-top: 4px solid #31287C;
            padding: 11px 13px;
            border-radius: 3px;
        }

        .kpi-card.accent-orange { border-top-color: #F6A500; }
        .kpi-card.accent-vert   { border-top-color: #15803d; }
        .kpi-card.accent-rouge  { border-top-color: #c62828; }
        .kpi-card.accent-violet { border-top-color: #31287C; }

        .kpi-valeur {
            font-size: 22pt;
            font-weight: bold;
            color: #31287C;
            line-height: 1;
            margin-bottom: 3px;
        }

        .kpi-valeur.couleur-vert   { color: #15803d; }
        .kpi-valeur.couleur-rouge  { color: #c62828; }
        .kpi-valeur.couleur-orange { color: #F6A500; }

        .kpi-label {
            font-size: 6.5pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .kpi-variation {
            font-size: 6.5pt;
            margin-top: 5px;
            padding: 2px 7px;
            border-radius: 2px;
            display: inline-block;
        }

        .var-hausse { background: #fce8e8; color: #c62828; }
        .var-baisse { background: #e8f5e9; color: #15803d; }
        .var-stable { background: #f0eeff; color: #31287C; }

        /* =====================================================================
           CONSTAT BOX
        ===================================================================== */
        .constat-box {
            background: #f0eeff;
            border-left: 5px solid #31287C;
            padding: 13px 16px;
            border-radius: 0 4px 4px 0;
        }

        .constat-text {
            font-size: 9pt;
            color: #1e1a3f;
            line-height: 1.75;
            text-align: justify;
        }

        /* =====================================================================
           TABLEAUX OFFICIELS
        ===================================================================== */
        .table-officielle {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        .table-officielle thead tr {
            background: #31287C;
            color: #ffffff;
        }

        .table-officielle thead th {
            padding: 8px 10px;
            text-align: left;
            font-size: 7.5pt;
            letter-spacing: 0.6px;
            font-weight: bold;
        }

        .table-officielle thead th.right { text-align: right; }

        .table-officielle tbody tr:nth-child(even) { background: #f4f3fb; }
        .table-officielle tbody tr:nth-child(odd)  { background: #ffffff; }

        .table-officielle tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e8e6f5;
            color: #334155;
        }

        .table-officielle tbody td.right  { text-align: right; font-weight: bold; }
        .table-officielle tbody td.center { text-align: center; }

        .table-officielle tfoot tr { background: #1e1a5c; color: #ffffff; }

        .table-officielle tfoot td {
            padding: 7px 10px;
            font-weight: bold;
            font-size: 8pt;
        }

        .table-officielle tfoot td.right { text-align: right; }

        /* =====================================================================
           BARRE PROGRESSION
        ===================================================================== */
        .barre-container {
            background: #e8e6f5;
            border-radius: 2px;
            height: 8px;
            width: 100%;
            display: inline-block;
            vertical-align: middle;
        }

        .barre-fill          { height: 8px; border-radius: 2px; background: #31287C; }
        .barre-fill.vert     { background: #15803d; }
        .barre-fill.rouge    { background: #c62828; }
        .barre-fill.orange   { background: #F6A500; }

        /* =====================================================================
           ALERTES
        ===================================================================== */
        .alerte-item {
            padding: 9px 12px;
            margin-bottom: 7px;
            border-radius: 3px;
            border-left: 4px solid;
            display: table;
            width: 100%;
        }

        .alerte-critique      { background: #fef2f2; border-color: #c62828; }
        .alerte-avertissement { background: #fff7ed; border-color: #ea580c; }
        .alerte-information   { background: #f0eeff; border-color: #31287C; }

        .alerte-icone {
            display: table-cell;
            width: 20px;
            vertical-align: top;
            font-size: 9pt;
            padding-top: 1px;
        }

        .alerte-body    { display: table-cell; vertical-align: top; }
        .alerte-titre   { font-size: 8.5pt; font-weight: bold; color: #1e293b; margin-bottom: 2px; }
        .alerte-message { font-size: 8pt; color: #475569; line-height: 1.5; }

        /* =====================================================================
           POINTS POSITIFS
        ===================================================================== */
        .positif-item {
            padding: 7px 12px;
            margin-bottom: 6px;
            background: #e8f5e9;
            border-left: 4px solid #15803d;
            border-radius: 3px;
            display: table;
            width: 100%;
        }

        .positif-icone {
            display: table-cell;
            width: 20px;
            vertical-align: top;
            font-size: 9pt;
        }

        .positif-text {
            display: table-cell;
            font-size: 8.5pt;
            color: #166534;
            line-height: 1.5;
            vertical-align: top;
        }

        /* =====================================================================
           GRAPHIQUE BARRES CSS
        ===================================================================== */
        .evolution-table { width: 100%; border-collapse: collapse; }

        .evolution-table td {
            vertical-align: bottom;
            text-align: center;
            padding: 0 2px;
            font-size: 6pt;
            color: #94a3b8;
        }

        .barre-dec {
            background: #31287C;
            border-radius: 2px 2px 0 0;
            width: 100%;
        }

        .barre-pub {
            background: #F6A500;
            border-radius: 2px 2px 0 0;
            width: 100%;
        }

        /* =====================================================================
           ANALYSE BLOC
        ===================================================================== */
        .analyse-bloc {
            background: #f4f3fb;
            border: 1px solid #e8e6f5;
            border-radius: 3px;
            padding: 11px 14px;
            margin-bottom: 9px;
        }

        .analyse-bloc-titre {
            font-size: 7.5pt;
            font-weight: bold;
            color: #31287C;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 5px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e8e6f5;
        }

        .analyse-bloc-texte {
            font-size: 8.5pt;
            color: #334155;
            line-height: 1.65;
            text-align: justify;
        }

        /* =====================================================================
           RECOMMANDATIONS
        ===================================================================== */
        .reco-item {
            display: table;
            width: 100%;
            margin-bottom: 8px;
            border: 1px solid #e8e6f5;
            border-radius: 3px;
            overflow: hidden;
        }

        .reco-numero {
            display: table-cell;
            width: 30px;
            background: #31287C;
            color: #F6A500;
            font-size: 12pt;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            padding: 10px 0;
        }

        .reco-body {
            display: table-cell;
            padding: 9px 13px;
            vertical-align: top;
            background: #f4f3fb;
        }

        .reco-titre {
            font-size: 8.5pt;
            font-weight: bold;
            color: #31287C;
            margin-bottom: 3px;
        }

        .reco-texte {
            font-size: 8pt;
            color: #475569;
            line-height: 1.55;
            text-align: justify;
        }

        .reco-acteurs { margin-top: 5px; }

        .reco-acteur-tag {
            display: inline-block;
            background: #f0eeff;
            color: #31287C;
            font-size: 6.5pt;
            padding: 2px 8px;
            border-radius: 10px;
            margin-right: 4px;
            border: 1px solid #d4cff5;
        }

        /* =====================================================================
           PIED DE PAGE FIXE
        ===================================================================== */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
        }

        .footer-bande {
            height: 3px;
            background: linear-gradient(90deg, #31287C, #F6A500 50%, #31287C);
        }

        .footer-content {
            background: #1e1a5c;
            padding: 7px 28px;
            display: table;
            width: 100%;
        }

        .footer-left  { display: table-cell; vertical-align: middle; }
        .footer-right { display: table-cell; vertical-align: middle; text-align: right; }

        .footer-text {
            font-size: 6.5pt;
            color: rgba(255,255,255,0.35);
        }

        .footer-officiel {
            font-size: 6.5pt;
            color: #F6A500;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        /* =====================================================================
           BADGES
        ===================================================================== */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 2px;
            font-size: 6.5pt;
            font-weight: bold;
        }

        .badge-violet { background: #f0eeff; color: #31287C; }
        .badge-orange { background: #fff3e0; color: #e65100; }
        .badge-vert   { background: #e8f5e9; color: #15803d; }
        .badge-rouge  { background: #fce8e8; color: #c62828; }

        /* =====================================================================
           UTILITAIRES
        ===================================================================== */
        .sep       { border: none; border-top: 1px solid #e8e6f5; margin: 15px 0; }
        .sep-leger { border: none; border-top: 1px dashed #e8e6f5; margin: 9px 0; }

        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-muted  { color: #94a3b8; font-size: 7.5pt; }
        .bold        { font-weight: bold; }
        .mt-8        { margin-top: 8px; }
        .mt-4        { margin-top: 4px; }

        .col-2 {
            display: table;
            width: 100%;
            border-spacing: 10px;
            border-collapse: separate;
        }

        .col-2-cell { display: table-cell; width: 50%; vertical-align: top; }

        .page-break { page-break-after: always; }
        .no-break   { page-break-inside: avoid; }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════════
     PIED DE PAGE FIXE
═══════════════════════════════════════════════════════════════════════ -->
<div class="footer">
    <div class="footer-bande"></div>
    <div class="footer-content">
        <div class="footer-left">
            <div class="footer-text">
                Rapport généré automatiquement par DocTrack — {{ $meta['genere_le'] }}
            </div>
            <div class="footer-text">
                Réf. {{ $meta['numero_rapport'] }} | {{ $meta['pays'] }}
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-officiel">Document officiel</div>
            <div class="footer-text">Usage institutionnel restreint</div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     PAGE 1
═══════════════════════════════════════════════════════════════════════ -->
<div class="page">

    <!-- EN-TÊTE -->
    <div class="header">
        <div class="header-bande-top"></div>
        <div class="header-deco-cercle"></div>
        <div class="header-deco-cercle-2"></div>
        <div class="header-content">
            <div class="header-left">
                <div class="header-republique">Plateforme nationale de gestion documentaire</div>
                <div class="header-pays">{{ $meta['pays'] }}</div>
                <div class="header-logo-text">Doc<span>Track</span></div>
                <div class="header-slogan">Retrouver vos documents perdus, partout au Sénégal</div>
            </div>
            <div class="header-right">
                <div class="header-badge-type">{{ $meta['type'] }}</div>
                <div class="header-ref">Réf. {{ $meta['numero_rapport'] }}</div>
                <div class="header-date">Généré le {{ $meta['genere_le'] }}</div>
            </div>
        </div>
        <div class="header-bande-bas"></div>
    </div>

    <!-- BANDEAU PÉRIODE + NIVEAU -->
    <div class="bandeau-periode">
        <div class="bandeau-left">
            <div class="bandeau-label">Période analysée</div>
            <div class="bandeau-periode-nom">{{ $stats['periode'] }}</div>
        </div>
        <div class="bandeau-right">
            <span class="bandeau-niveau niveau-{{ $analyse['niveau_global']['niveau'] }}">
                {{ $analyse['niveau_global']['icone'] }} {{ $analyse['niveau_global']['label'] }}
            </span>
        </div>
    </div>

    <!-- CORPS PAGE 1 -->
    <div class="body-content">

        <!-- ══ SECTION 1 — CHIFFRES CLÉS ══ -->
        <div class="section">
            <div class="section-titre">
                <span class="section-titre-accent">1. Chiffres clés de la période</span>
                <span class="section-titre-numero">§1</span>
            </div>

            <div class="kpi-grid">
                <div class="kpi-row">

                    <div class="kpi-cell">
                        <div class="kpi-card accent-violet">
                            <div class="kpi-valeur">{{ number_format($stats['declarations']['total'], 0, ',', ' ') }}</div>
                            <div class="kpi-label">Déclarations de perte</div>
                            @php $varD = $stats['comparaison']['declarations_variation']; @endphp
                            @if($varD['sens'] !== 'neutre')
                            <div class="kpi-variation var-{{ $varD['sens'] }}">
                                {{ $varD['sens'] === 'hausse' ? '↑' : '↓' }} {{ $varD['valeur'] }}% vs période préc.
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="kpi-cell">
                        <div class="kpi-card accent-orange">
                            <div class="kpi-valeur couleur-orange">{{ number_format($stats['publications']['total'], 0, ',', ' ') }}</div>
                            <div class="kpi-label">Documents publiés</div>
                            @php $varP = $stats['comparaison']['publications_variation']; @endphp
                            @if($varP['sens'] !== 'neutre')
                            <div class="kpi-variation var-{{ $varP['sens'] }}">
                                {{ $varP['sens'] === 'hausse' ? '↑' : '↓' }} {{ $varP['valeur'] }}% vs période préc.
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="kpi-cell">
                        <div class="kpi-card accent-vert">
                            <div class="kpi-valeur {{ $stats['publications']['taux_restitution'] >= 50 ? 'couleur-vert' : ($stats['publications']['taux_restitution'] < 25 ? 'couleur-rouge' : '') }}">
                                {{ $stats['publications']['taux_restitution'] }}%
                            </div>
                            <div class="kpi-label">Taux de restitution</div>
                            <div class="kpi-variation var-stable">
                                {{ $stats['publications']['recuperes'] }} doc(s) restitué(s)
                            </div>
                        </div>
                    </div>

                    <div class="kpi-cell">
                        <div class="kpi-card accent-rouge">
                            <div class="kpi-valeur">{{ $stats['par_region']['nombre_localites'] }}</div>
                            <div class="kpi-label">Localités couvertes</div>
                            <div class="kpi-variation var-stable">
                                {{ $stats['utilisateurs']['actifs'] }} utilisateur(s) actif(s)
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══ SECTION 2 — CONSTAT GÉNÉRAL ══ -->
        <div class="section">
            <div class="section-titre">
                <span class="section-titre-accent">2. Constat général</span>
                <span class="section-titre-numero">§2</span>
            </div>
            <div class="constat-box">
                <div class="constat-text">{{ $analyse['constat_general'] }}</div>
            </div>
        </div>

        <!-- ══ SECTION 3 — RÉPARTITION PAR TYPE ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">3. Répartition par type de document</span>
                <span class="section-titre-numero">§3</span>
            </div>

            <div class="col-2">
                <div class="col-2-cell">
                    <p class="text-muted bold" style="margin-bottom:6px;">Déclarations de perte</p>
                    <table class="table-officielle">
                        <thead>
                            <tr>
                                <th>Type de document</th>
                                <th class="right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($stats['par_type']['declarations'] as $item)
                            <tr>
                                <td>{{ $item['type'] }}</td>
                                <td class="right">{{ $item['total'] }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="2" class="text-center text-muted">Aucune déclaration</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL</td>
                                <td class="right">{{ $stats['declarations']['total'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="col-2-cell">
                    <p class="text-muted bold" style="margin-bottom:6px;">Documents trouvés publiés</p>
                    <table class="table-officielle">
                        <thead>
                            <tr>
                                <th>Type de document</th>
                                <th class="right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($stats['par_type']['publications'] as $item)
                            <tr>
                                <td>{{ $item['type'] }}</td>
                                <td class="right">{{ $item['total'] }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="2" class="text-center text-muted">Aucune publication</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL</td>
                                <td class="right">{{ $stats['publications']['total'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ SECTION 4 — ANALYSE GÉOGRAPHIQUE ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">4. Analyse géographique</span>
                <span class="section-titre-numero">§4</span>
            </div>

            <div class="analyse-bloc" style="margin-bottom:10px;">
                <div class="analyse-bloc-titre">Synthèse territoriale</div>
                <div class="analyse-bloc-texte">{{ $analyse['analyse_geographique'] }}</div>
            </div>

            @if($stats['par_region']['liste']->count() > 0)
            <table class="table-officielle">
                <thead>
                    <tr>
                        <th>Localité</th>
                        <th class="right">Publications</th>
                        <th class="right">Restitués</th>
                        <th class="right">Non restitués</th>
                        <th class="right">Taux</th>
                        <th>Progression</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stats['par_region']['liste'] as $region)
                    @php
                        $taux = $region['taux_restitution'];
                        $barreClass = $taux >= 50 ? 'vert' : ($taux < 25 ? 'rouge' : 'orange');
                        $largeur = min($taux, 100);
                    @endphp
                    <tr>
                        <td>
                            {{ $region['region'] }}
                            @if(isset($stats['par_region']['zones_critiques']) && $stats['par_region']['zones_critiques']->contains('region', $region['region']))
                                <span class="badge badge-rouge">Zone critique</span>
                            @endif
                        </td>
                        <td class="right">{{ $region['total_publications'] }}</td>
                        <td class="right">{{ $region['recuperes'] }}</td>
                        <td class="right">{{ $region['non_recuperes'] }}</td>
                        <td class="right">
                            <span class="badge {{ $taux >= 50 ? 'badge-vert' : ($taux < 25 ? 'badge-rouge' : 'badge-orange') }}">
                                {{ $taux }}%
                            </span>
                        </td>
                        <td style="width:80px; padding-right:12px;">
                            <div class="barre-container">
                                <div class="barre-fill {{ $barreClass }}" style="width:{{ $largeur }}%;"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

    </div><!-- /body-content page 1 -->
</div><!-- /page 1 -->

<!-- ═══════════════════════════════════════════════════════════════════════
     PAGE 2
═══════════════════════════════════════════════════════════════════════ -->
<div class="page-break"></div>
<div class="page">
    <div class="body-content">

        <!-- ══ SECTION 5 — ÉVOLUTION ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">
                    5. Évolution {{ $stats['evolution']['type'] === 'mensuelle' ? 'mensuelle' : 'journalière' }}
                </span>
                <span class="section-titre-numero">§5</span>
            </div>

            <div class="analyse-bloc" style="margin-bottom:10px;">
                <div class="analyse-bloc-titre">Tendance observée</div>
                <div class="analyse-bloc-texte">{{ $analyse['analyse_temporelle'] }}</div>
            </div>

            @php
                $evolutionData = $stats['evolution']['donnees'];
                $maxVal = collect($evolutionData)->max(fn($d) => max($d['declarations'], $d['publications']));
                $maxVal = max($maxVal, 1);
                $hauteurMax = 50;
            @endphp

            <table class="evolution-table">
                <tr>
                    @foreach($evolutionData as $point)
                    @php
                        $hDec = round(($point['declarations'] / $maxVal) * $hauteurMax);
                        $hPub = round(($point['publications'] / $maxVal) * $hauteurMax);
                    @endphp
                    <td style="width:{{ round(100 / count($evolutionData), 2) }}%;">
                        <div style="height:{{ $hauteurMax }}px; position:relative; display:flex; align-items:flex-end; justify-content:center; gap:1px;">
                            <div class="barre-dec" style="height:{{ $hDec }}px; width:45%;"></div>
                            <div class="barre-pub" style="height:{{ $hPub }}px; width:45%;"></div>
                        </div>
                        <div style="font-size:5.5pt; color:#94a3b8; text-align:center; margin-top:2px;">
                            {{ is_string($point['label']) ? substr($point['label'], 0, 3) : $point['label'] }}
                        </div>
                    </td>
                    @endforeach
                </tr>
            </table>

            <!-- Légende -->
            <div class="mt-4" style="text-align:center;">
                <span style="display:inline-block; width:10px; height:10px; background:#31287C; border-radius:1px; margin-right:4px; vertical-align:middle;"></span>
                <span class="text-muted">Déclarations</span>
                &nbsp;&nbsp;
                <span style="display:inline-block; width:10px; height:10px; background:#F6A500; border-radius:1px; margin-right:4px; vertical-align:middle;"></span>
                <span class="text-muted">Publications</span>
            </div>

            <div class="mt-8">
                <table class="table-officielle">
                    <thead>
                        <tr>
                            <th>{{ $stats['evolution']['type'] === 'mensuelle' ? 'Mois' : 'Jour' }}</th>
                            <th class="right">Déclarations</th>
                            <th class="right">Publications</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($evolutionData as $point)
                        @if($point['declarations'] > 0 || $point['publications'] > 0)
                        <tr>
                            <td>{{ $point['label'] }}</td>
                            <td class="right">{{ $point['declarations'] }}</td>
                            <td class="right">{{ $point['publications'] }}</td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <hr class="sep">

        <!-- ══ SECTION 6 — ALERTES ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">6. Alertes et points d'attention</span>
                <span class="section-titre-numero">§6</span>
            </div>

            <div class="col-2">
                <div class="col-2-cell">
                    <p class="text-muted bold" style="margin-bottom:6px;">Points d'attention</p>
                    @forelse($analyse['alertes'] as $alerte)
                    <div class="alerte-item alerte-{{ $alerte['niveau'] }} no-break">
                        <div class="alerte-icone">{{ $alerte['icone'] }}</div>
                        <div class="alerte-body">
                            <div class="alerte-titre">{{ $alerte['titre'] }}</div>
                            <div class="alerte-message">{{ $alerte['message'] }}</div>
                        </div>
                    </div>
                    @empty
                    <div class="analyse-bloc">
                        <div class="analyse-bloc-texte text-center">Aucune alerte pour cette période.</div>
                    </div>
                    @endforelse
                </div>

                <div class="col-2-cell">
                    <p class="text-muted bold" style="margin-bottom:6px;">Points positifs</p>
                    @forelse($analyse['points_positifs'] as $positif)
                    <div class="positif-item no-break">
                        <div class="positif-icone">{{ $positif['icone'] }}</div>
                        <div class="positif-text">{{ $positif['message'] }}</div>
                    </div>
                    @empty
                    <div class="analyse-bloc">
                        <div class="analyse-bloc-texte text-center text-muted">Aucun point positif identifié.</div>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <hr class="sep">

        <!-- ══ SECTION 7 — RECOMMANDATIONS ══ -->
        <div class="section">
            <div class="section-titre">
                <span class="section-titre-accent">7. Recommandations</span>
                <span class="section-titre-numero">§7</span>
            </div>

            @foreach($analyse['recommandations'] as $reco)
            <div class="reco-item no-break">
                <div class="reco-numero">{{ $reco['priorite'] }}</div>
                <div class="reco-body">
                    <div class="reco-titre">{{ $reco['titre'] }}</div>
                    <div class="reco-texte">{{ $reco['texte'] }}</div>
                    @if(!empty($reco['acteurs']))
                    <div class="reco-acteurs">
                        @foreach($reco['acteurs'] as $acteur)
                        <span class="reco-acteur-tag">{{ $acteur }}</span>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        <hr class="sep">

        <!-- ══ SECTION 8 — DONNÉES COMPLÉMENTAIRES ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">8. Données complémentaires</span>
                <span class="section-titre-numero">§8</span>
            </div>

            <div class="col-2">
                <div class="col-2-cell">
                    <div class="analyse-bloc">
                        <div class="analyse-bloc-titre">Restitutions</div>
                        <table style="width:100%; font-size:8.5pt;">
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Demandes de restitution</td>
                                <td style="text-align:right; font-weight:bold; color:#31287C;">{{ $stats['restitutions']['demandes_restitution'] }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Documents effectivement récupérés</td>
                                <td style="text-align:right; font-weight:bold; color:#15803d;">{{ $stats['restitutions']['documents_recuperes'] }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Documents non récupérés</td>
                                <td style="text-align:right; font-weight:bold; color:#c62828;">{{ $stats['publications']['non_recuperes'] }}</td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="col-2-cell">
                    <div class="analyse-bloc">
                        <div class="analyse-bloc-titre">Utilisateurs</div>
                        <table style="width:100%; font-size:8.5pt;">
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Nouveaux inscrits sur la période</td>
                                <td style="text-align:right; font-weight:bold; color:#31287C;">{{ $stats['utilisateurs']['nouveaux_inscrits'] }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Utilisateurs actifs</td>
                                <td style="text-align:right; font-weight:bold; color:#15803d;">{{ $stats['utilisateurs']['actifs'] }}</td>
                            </tr>
                            <tr>
                                <td style="padding:4px 0; color:#475569;">Déclarations supprimées (soft)</td>
                                <td style="text-align:right; font-weight:bold; color:#64748b;">{{ $stats['declarations']['supprimees'] }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ CONCLUSION ══ -->
        <div class="section no-break">
            <div class="section-titre">
                <span class="section-titre-accent">Conclusion</span>
            </div>

            <div class="constat-box" style="background:#fff8ec; border-color:#F6A500;">
                <div class="constat-text">
                    Ce rapport institutionnel {{ strtolower($meta['type']) }} DocTrack couvre la période
                    <strong>{{ $stats['periode'] }}</strong> et synthétise l'ensemble des activités
                    enregistrées sur la plateforme nationale de déclaration et de restitution de documents perdus.
                    Les données présentées sont extraites automatiquement du système DocTrack
                    (réf. <strong>{{ $meta['numero_rapport'] }}</strong>).
                    @if(count($analyse['recommandations']) > 0)
                        Un total de <strong>{{ count($analyse['recommandations']) }} recommandation(s)</strong>
                        ont été formulées pour améliorer les performances du dispositif sur la prochaine période.
                    @endif
                    Ce document est à usage institutionnel et destiné aux autorités compétentes
                    de la <strong>{{ $meta['pays'] }}</strong>.
                </div>
            </div>

            <!-- Bloc signature -->
            <div style="margin-top:24px; display:table; width:100%;">
                <div style="display:table-cell; width:50%; vertical-align:top; padding-right:20px;">
                    <div style="border-top:2px solid #F6A500; padding-top:7px;">
                        <div class="text-muted bold" style="color:#31287C; font-size:7.5pt;">Généré par le système DocTrack</div>
                        <div class="text-muted">{{ $meta['genere_le'] }}</div>
                    </div>
                </div>
                <div style="display:table-cell; width:50%; vertical-align:top; text-align:right; padding-left:20px;">
                    <div style="border-top:2px solid #e8e6f5; padding-top:7px;">
                        <div class="text-muted bold" style="color:#31287C; font-size:7.5pt;">Visa autorité compétente</div>
                        <div class="text-muted">&nbsp;</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /body-content page 2 -->
</div><!-- /page 2 -->

</body>
</html>

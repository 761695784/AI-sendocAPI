**`certificat.blade.php`** — version redessinée :

```html
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Certificat de Perte — DocTrack</title>
    <style>
        /* =====================================================================
           BASE
        ===================================================================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 9.5pt;
            color: #1e1a3f;
            background: #ffffff;
            line-height: 1.6;
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

        .header-bande-top {
            height: 6px;
            background: #F6A500;
        }

        /* Cercles décoratifs */
        .deco-cercle-1 {
            position: absolute;
            right: -30px;
            top: -30px;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: rgba(246,165,0,0.07);
        }

        .deco-cercle-2 {
            position: absolute;
            right: 70px;
            top: -50px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(246,165,0,0.05);
        }

        .header-content {
            padding: 20px 32px 18px;
            display: table;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .header-left  { display: table-cell; vertical-align: middle; width: 20%; }
        .header-center{ display: table-cell; vertical-align: middle; text-align: center; width: 60%; }
        .header-right { display: table-cell; vertical-align: middle; text-align: right; width: 20%; }

        .header-logo {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 2px solid rgba(246,165,0,0.4);
            object-fit: contain;
            background: rgba(255,255,255,0.1);
            padding: 4px;
        }

        .header-republique {
            font-size: 7pt;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .header-pays {
            font-size: 9pt;
            color: #F6A500;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .header-ministere {
            font-size: 7.5pt;
            color: rgba(255,255,255,0.65);
            margin-bottom: 10px;
            font-style: italic;
        }

        .header-plateforme {
            font-size: 22pt;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: 3px;
            line-height: 1;
        }

        .header-plateforme span { color: #F6A500; }

        .header-slogan {
            font-size: 7pt;
            color: rgba(255,255,255,0.45);
            margin-top: 4px;
            font-style: italic;
        }

        .header-num-cert {
            font-size: 6.5pt;
            color: rgba(255,255,255,0.45);
            margin-bottom: 3px;
        }

        .header-badge {
            display: inline-block;
            background: #F6A500;
            color: #31287C;
            font-size: 7pt;
            font-weight: bold;
            padding: 4px 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            border-radius: 2px;
        }

        .header-bande-bas {
            height: 3px;
            background: linear-gradient(90deg, #F6A500 0%, #31287C 60%);
        }

        /* =====================================================================
           BANDEAU TITRE
        ===================================================================== */
        .bandeau-titre {
            background: #1e1a5c;
            padding: 14px 32px;
            text-align: center;
        }

        .bandeau-titre-text {
            font-size: 16pt;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .bandeau-titre-text span { color: #F6A500; }

        .bandeau-sous-titre {
            font-size: 8pt;
            color: rgba(255,255,255,0.45);
            margin-top: 3px;
            letter-spacing: 1px;
        }

        /* =====================================================================
           CORPS PRINCIPAL
        ===================================================================== */
        .body-content {
            padding: 28px 36px 40px;
        }

        /* =====================================================================
           BOX NUMÉRO DE CERTIFICAT
        ===================================================================== */
        .cert-num-box {
            display: table;
            width: 100%;
            margin-bottom: 24px;
        }

        .cert-num-left  { display: table-cell; vertical-align: middle; }
        .cert-num-right { display: table-cell; vertical-align: middle; text-align: right; }

        .cert-ref {
            font-size: 8pt;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cert-ref-value {
            font-size: 12pt;
            font-weight: bold;
            color: #31287C;
            font-family: "Courier New", monospace;
            letter-spacing: 1.5px;
        }

        .cert-date-box {
            background: #f4f3fb;
            border: 1px solid #e8e6f5;
            border-radius: 4px;
            padding: 8px 14px;
            text-align: right;
        }

        .cert-date-label {
            font-size: 6.5pt;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 2px;
        }

        .cert-date-value {
            font-size: 9pt;
            font-weight: bold;
            color: #31287C;
        }

        /* =====================================================================
           TEXTE D'ATTESTATION
        ===================================================================== */
        .attestation-box {
            background: #f4f3fb;
            border-left: 5px solid #31287C;
            border-radius: 0 6px 6px 0;
            padding: 16px 20px;
            margin-bottom: 24px;
        }

        .attestation-intro {
            font-size: 9pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .attestation-text {
            font-size: 10.5pt;
            color: #1e1a3f;
            line-height: 1.8;
            text-align: justify;
        }

        .attestation-text strong { color: #31287C; }

        /* =====================================================================
           GRILLE D'INFORMATIONS
        ===================================================================== */
        .info-section-title {
            font-size: 8pt;
            font-weight: bold;
            color: #31287C;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border-bottom: 2.5px solid #31287C;
            padding-bottom: 5px;
            margin-bottom: 14px;
        }

        .info-section-title::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #F6A500;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }

        .info-grid {
            display: table;
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin-bottom: 20px;
        }

        .info-row { display: table-row; }

        .info-cell {
            display: table-cell;
            width: 50%;
        }

        .info-item {
            background: #ffffff;
            border: 1px solid #e8e6f5;
            border-top: 3px solid #31287C;
            border-radius: 3px;
            padding: 10px 13px;
        }

        .info-item.accent-orange { border-top-color: #F6A500; }
        .info-item.accent-vert   { border-top-color: #15803d; }

        .info-label {
            font-size: 6.5pt;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 11pt;
            font-weight: bold;
            color: #31287C;
        }

        .info-value.orange { color: #e65100; }
        .info-value.vert   { color: #15803d; }

        /* =====================================================================
           SECTION VALIDITÉ
        ===================================================================== */
        .validite-box {
            background: #fff8ec;
            border: 1px solid rgba(246,165,0,0.3);
            border-left: 5px solid #F6A500;
            border-radius: 0 4px 4px 0;
            padding: 12px 16px;
            margin-bottom: 24px;
            display: table;
            width: 100%;
        }

        .validite-left  { display: table-cell; vertical-align: middle; width: 80%; }
        .validite-right { display: table-cell; vertical-align: middle; text-align: right; width: 20%; }

        .validite-title {
            font-size: 8pt;
            font-weight: bold;
            color: #e65100;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }

        .validite-text {
            font-size: 8.5pt;
            color: #475569;
            line-height: 1.6;
        }

        .validite-icon {
            font-size: 22pt;
            color: #F6A500;
            font-weight: bold;
        }

        /* =====================================================================
           BLOC SIGNATURE
        ===================================================================== */
        .signature-section {
            display: table;
            width: 100%;
            margin-top: 32px;
            border-spacing: 16px;
            border-collapse: separate;
        }

        .sig-cell { display: table-cell; width: 50%; vertical-align: top; }

        .sig-box {
            border-top: 2px solid #e8e6f5;
            padding-top: 10px;
        }

        .sig-box.accent { border-top-color: #F6A500; }

        .sig-label {
            font-size: 7pt;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 3px;
        }

        .sig-name {
            font-size: 9pt;
            font-weight: bold;
            color: #31287C;
        }

        .sig-sub {
            font-size: 7.5pt;
            color: #aaa;
            margin-top: 2px;
        }

        .sig-espace {
            height: 50px;
            border: 1px dashed #e8e6f5;
            border-radius: 3px;
            margin-top: 10px;
            background: #faf9ff;
        }

        /* =====================================================================
           CACHET OFFICIEL (simulé)
        ===================================================================== */
        .cachet-wrap {
            text-align: center;
            margin-top: 8px;
        }

        .cachet {
            display: inline-block;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 3px double #31287C;
            background: rgba(49,40,124,0.04);
            position: relative;
        }

        .cachet-inner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .cachet-text {
            font-size: 5pt;
            color: #31287C;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            line-height: 1.4;
        }

        .cachet-star {
            font-size: 8pt;
            color: #F6A500;
        }

        /* =====================================================================
           PIED DE PAGE
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
            padding: 7px 32px;
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
           UTILITAIRES
        ===================================================================== */
        .sep {
            border: none;
            border-top: 1px solid #e8e6f5;
            margin: 18px 0;
        }

        .text-center { text-align: center; }
        .mt-8 { margin-top: 8px; }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════
     PIED DE PAGE FIXE
═══════════════════════════════════════════════════════════════ -->
<div class="footer">
    <div class="footer-bande"></div>
    <div class="footer-content">
        <div class="footer-left">
            <div class="footer-text">
                Certificat généré automatiquement par DocTrack
                — {{ \Carbon\Carbon::now()->format('d/m/Y à H:i') }}
            </div>
            <div class="footer-text">
                Réf. {{ $certificat->certificat_number }}
                | République du Sénégal
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-officiel">Document officiel</div>
            <div class="footer-text">Usage administratif</div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE
═══════════════════════════════════════════════════════════════ -->
<div class="page">

    <!-- EN-TÊTE -->
    <div class="header">
        <div class="header-bande-top"></div>
        <div class="deco-cercle-1"></div>
        <div class="deco-cercle-2"></div>
        <div class="header-content">

            <!-- Logo drapeau -->
            <div class="header-left">
                <img src="{{ public_path('images/drapeau.png') }}"
                     class="header-logo"
                     alt="Drapeau du Sénégal">
            </div>

            <!-- Centre : République + plateforme -->
            <div class="header-center">
                <div class="header-republique">Plateforme nationale de gestion documentaire</div>
                <div class="header-pays">République du Sénégal</div>
                <div class="header-ministere">
                    Ministère de l'Intérieur et de la Sécurité Publique
                </div>
                <div class="header-plateforme">Doc<span>Track</span></div>
                <div class="header-slogan">
                    Retrouver vos documents perdus, partout au Sénégal
                </div>
            </div>

            <!-- Badge type + référence -->
            <div class="header-right">
                <div class="header-num-cert">Certificat N°</div>
                <div class="header-badge">Officiel</div>
            </div>

        </div>
        <div class="header-bande-bas"></div>
    </div>

    <!-- BANDEAU TITRE -->
    <div class="bandeau-titre">
        <div class="bandeau-titre-text">
            Certificat de <span>Perte</span>
        </div>
        <div class="bandeau-sous-titre">
            Document officiel — Déclaration de perte de document
        </div>
    </div>

    <!-- CORPS -->
    <div class="body-content">

        <!-- Numéro de certificat + date -->
        <div class="cert-num-box">
            <div class="cert-num-left">
                <div class="cert-ref">Référence du certificat</div>
                <div class="cert-ref-value">{{ $certificat->certificat_number }}</div>
            </div>
            <div class="cert-num-right">
                <div class="cert-date-box">
                    <div class="cert-date-label">Émis le</div>
                    <div class="cert-date-value">
                        {{ \Carbon\Carbon::now()->format('d/m/Y') }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Texte d'attestation -->
        <div class="attestation-box">
            <div class="attestation-intro">Attestation officielle</div>
            <div class="attestation-text">
                La plateforme nationale <strong>DocTrack</strong>, sous l'autorité du
                <strong>Ministère de l'Intérieur et de la Sécurité Publique</strong>
                de la République du Sénégal, certifie que
                <strong>{{ $certificat->declarationDePerte->FirstNameInDoc }}
                {{ $certificat->declarationDePerte->LastNameInDoc }}</strong>
                a effectué une déclaration officielle de perte de document auprès
                de la plateforme nationale DocTrack, conformément aux dispositions
                en vigueur sur le territoire sénégalais.
            </div>
        </div>

        <!-- Informations sur le document perdu -->
        <div class="info-section-title">Informations sur le document déclaré perdu</div>

        <div class="info-grid">
            <div class="info-row">

                <div class="info-cell">
                    <div class="info-item">
                        <div class="info-label">Prénom du titulaire</div>
                        <div class="info-value">
                            {{ $certificat->declarationDePerte->FirstNameInDoc }}
                        </div>
                    </div>
                </div>

                <div class="info-cell">
                    <div class="info-item">
                        <div class="info-label">Nom du titulaire</div>
                        <div class="info-value">
                            {{ $certificat->declarationDePerte->LastNameInDoc }}
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="info-grid">
            <div class="info-row">

                <div class="info-cell">
                    <div class="info-item accent-orange">
                        <div class="info-label">Type de document</div>
                        <div class="info-value orange">
                            {{ $certificat->documentType->TypeName ?? 'Non précisé' }}
                        </div>
                    </div>
                </div>

                <div class="info-cell">
                    <div class="info-item accent-vert">
                        <div class="info-label">Numéro d'identification</div>
                        <div class="info-value vert">
                            {{ $certificat->declarationDePerte->DocIdentification ?? 'Non renseigné' }}
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <hr class="sep">

        <!-- Validité et usage -->
        <div class="validite-box">
            <div class="validite-left">
                <div class="validite-title">Validité et usage du certificat</div>
                <div class="validite-text">
                    Ce certificat est délivré à titre de preuve de déclaration de perte.
                    Il peut être présenté aux autorités compétentes, administrations,
                    banques et institutions pour justifier la perte du document indiqué
                    ci-dessus. Il ne constitue pas un document de remplacement.
                </div>
            </div>
            <div class="validite-right">
                <div class="validite-icon">&#9888;</div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-section">

            <!-- Gauche : système -->
            <div class="sig-cell">
                <div class="sig-box accent">
                    <div class="sig-label">Généré par</div>
                    <div class="sig-name">Système DocTrack</div>
                    <div class="sig-sub">
                        {{ \Carbon\Carbon::now()->format('d/m/Y à H:i') }}
                    </div>
                </div>
                <!-- Cachet -->
                <div class="cachet-wrap mt-8">
                    <div class="cachet">
                        <div class="cachet-inner">
                            <div class="cachet-star">&#9733;</div>
                            <div class="cachet-text">
                                DOC<br>TRACK<br>SN
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Droite : autorité -->
            <div class="sig-cell">
                <div class="sig-box">
                    <div class="sig-label">Visa de l'autorité compétente</div>
                    <div class="sig-name">Ministère de l'Intérieur</div>
                    <div class="sig-sub">République du Sénégal</div>
                </div>
                <div class="sig-espace"></div>
            </div>

        </div>

    </div><!-- /body-content -->
</div><!-- /page -->

</body>
</html>
```

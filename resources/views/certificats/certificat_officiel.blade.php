<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Certificat de déclaration de perte — {{ $certificat->certificat_number }}</title>
    <style>
        @page { margin: 28px 36px 60px 36px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10.5px; color: #1f2430; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #5b6272; }
        .small { font-size: 9px; }

        .header td { vertical-align: top; }
        .institution { font-size: 15px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .structure { font-size: 12px; font-weight: bold; margin-top: 2px; }
        .refbox { border: 1.5px solid #1f2430; padding: 8px 10px; text-align: right; }
        .refbox .num { font-size: 13px; font-weight: bold; }
        .rule { border-top: 2px solid #1f2430; margin: 12px 0 14px 0; }

        .title { text-align: center; font-size: 17px; font-weight: bold; letter-spacing: 1px; margin: 4px 0 2px 0; }
        .subtitle { text-align: center; font-size: 10px; margin-bottom: 16px; }

        .para { text-align: justify; margin-bottom: 12px; }
        .items th { background: #eef0f4; text-align: left; padding: 6px 7px; font-size: 9.5px; border: 1px solid #c9cdd6; }
        .items td { padding: 6px 7px; border: 1px solid #c9cdd6; font-size: 10px; }

        .facts td { padding: 3px 0; }
        .facts td.label { width: 150px; color: #5b6272; }

        .notice { border-left: 3px solid #1f2430; padding: 6px 10px; background: #f6f7f9; font-size: 9.5px; margin: 14px 0; }

        .sign td { vertical-align: top; }
        .stamp { border: 1px dashed #8a90a0; height: 95px; text-align: center; color: #8a90a0; font-size: 9px; padding-top: 38px; }
        .verify { border: 1px solid #c9cdd6; padding: 8px; }
        .code { font-size: 12px; font-weight: bold; letter-spacing: 1px; }

        .revoked { color: #b3261e; border: 2px solid #b3261e; padding: 6px; text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 10px; }

        .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8.5px; color: #5b6272; border-top: 1px solid #c9cdd6; padding-top: 5px; }
    </style>
</head>
<body>

<div class="footer">
    Certificat émis via la plateforme DocTrack le {{ $certificat->created_at->timezone('Africa/Dakar')->format('d/m/Y à H:i') }}
    — Réf. {{ $declaration->reference }} — Vérification : {{ $certificat->verificationUrl() }}
</div>

@if ($certificat->isRevoked())
    <div class="revoked">CERTIFICAT RÉVOQUÉ LE {{ $certificat->revoked_at->format('d/m/Y') }} — {{ $certificat->revocation_reason }}</div>
@endif

<table class="header">
    <tr>
        <td style="width: 62%;">
            <div class="institution">{{ $institution->name }}</div>
            @if ($structure->parent && $structure->parent->id !== $institution->id)
                <div class="muted">{{ $structure->parent->name }}</div>
            @endif
            <div class="structure">{{ $structure->name }}</div>
            <div class="muted small">
                {{ $structure->address }}@if($structure->address && $structure->territory), @endif{{ $structure->territory?->name }}
                @if ($structure->phone) — Tél. {{ $structure->phone }} @endif
            </div>
        </td>
        <td style="width: 38%;">
            <div class="refbox">
                <div class="small muted">Certificat N°</div>
                <div class="num">{{ $certificat->certificat_number }}</div>
                <div class="small muted" style="margin-top: 4px;">Dossier {{ $declaration->reference }}</div>
                <div class="small muted">Délivré le {{ $certificat->created_at->timezone('Africa/Dakar')->format('d/m/Y') }}</div>
            </div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<div class="title">CERTIFICAT DE DÉCLARATION DE PERTE</div>
<div class="subtitle muted">Déclaration reçue au guichet de la structure</div>

@php
    $citizen = $declaration->citizenProfile;
    $agentName = $agent ? trim($agent->FirstName . ' ' . $agent->LastName) : '—';
@endphp

<div class="para">
    Nous, <strong>{{ $agentName }}</strong>@if ($matricule), matricule <strong>{{ $matricule }}</strong>@endif,
    en service au <strong>{{ $structure->name }}</strong>, certifions que
    <strong>{{ $citizen?->first_name }} {{ $citizen?->last_name }}</strong>@if ($citizen?->birth_date),
    né(e) le {{ $citizen->birth_date->format('d/m/Y') }}@endif,
    s'est présenté(e) ce jour et a déclaré la perte du ou des document(s) suivant(s) :
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 28%;">Type de document</th>
            <th style="width: 34%;">Titulaire (tel qu'inscrit sur le document)</th>
            <th style="width: 18%;">Né(e) le</th>
            <th style="width: 20%;">Numéro</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($declaration->items as $item)
            <tr>
                <td>{{ $item->documentType?->TypeName }}</td>
                <td>{{ $item->first_name }} {{ $item->last_name }}</td>
                <td>{{ $item->birth_date_in_doc?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $item->docNumber() ?? 'Non connu' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="facts" style="margin-top: 12px;">
    <tr>
        <td class="label">Date de la perte</td>
        <td>{{ $declaration->lost_at?->format('d/m/Y') ?? 'Non précisée' }}</td>
    </tr>
    <tr>
        <td class="label">Lieu de la perte</td>
        <td>{{ $declaration->lost_place ?? 'Non précisé' }}@if ($declaration->territory) ({{ $declaration->territory->name }})@endif</td>
    </tr>
    @if ($declaration->circumstances)
        <tr>
            <td class="label">Circonstances</td>
            <td>{{ $declaration->circumstances }}</td>
        </tr>
    @endif
</table>

<div class="notice">
    Le présent certificat atteste de la déclaration de perte. Il est délivré pour servir et valoir ce que de droit
    et ne constitue en aucun cas une pièce d'identité ni un document de remplacement.
    Toute fausse déclaration expose son auteur aux poursuites prévues par la loi.
</div>

<table class="sign">
    <tr>
        <td style="width: 34%; padding-right: 12px;">
            <div class="small muted">L'agent</div>
            <div style="margin-top: 4px; font-weight: bold;">{{ $agentName }}</div>
            @if ($matricule)<div class="small">Matricule {{ $matricule }}</div>@endif
            <div class="small muted" style="margin-top: 36px;">Signature</div>
        </td>
        <td style="width: 32%; padding-right: 12px;">
            <div class="stamp">Cachet de la structure</div>
        </td>
        <td style="width: 34%;">
            <div class="verify">
                <table>
                    <tr>
                        <td style="width: 78px;"><img src="{{ $qrDataUri }}" style="width: 74px; height: 74px;"></td>
                        <td style="padding-left: 6px; vertical-align: top;">
                            <div class="small muted">Vérifier l'authenticité</div>
                            <div class="small">Scannez le QR code ou saisissez le code :</div>
                            <div class="code" style="margin-top: 4px;">{{ $certificat->formattedVerificationCode() }}</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

</body>
</html>

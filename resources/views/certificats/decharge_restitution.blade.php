<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Décharge de restitution — {{ $r->reference }}</title>
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
        .section { font-weight: bold; font-size: 11px; margin: 14px 0 6px 0; text-transform: uppercase; letter-spacing: .5px; }
        .facts td { padding: 3px 0; }
        .facts td.label { width: 170px; color: #5b6272; }
        .para { text-align: justify; margin: 12px 0; }
        .notice { border-left: 3px solid #1f2430; padding: 6px 10px; background: #f6f7f9; font-size: 9.5px; margin: 12px 0; }
        .sign td { vertical-align: top; width: 50%; }
        .signbox { border: 1px solid #8a90a0; height: 90px; margin-top: 4px; }
        .verify { border: 1px solid #c9cdd6; padding: 8px; }
        .code { font-size: 12px; font-weight: bold; letter-spacing: 1px; }
        .voided { color: #b3261e; border: 2px solid #b3261e; padding: 6px; text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 10px; }
        .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8.5px; color: #5b6272; border-top: 1px solid #c9cdd6; padding-top: 5px; }
    </style>
</head>
<body>
@php
    $at = $r->handed_over_at->timezone('Africa/Dakar');
    $mask = fn ($last4) => $last4 ? '•••• ' . $last4 : '—';
@endphp

<div class="footer">
    Décharge émise via la plateforme DocTrack le {{ $at->format('d/m/Y à H:i') }} — Réf. {{ $r->reference }} — Vérification : {{ $r->verificationUrl() }}
</div>

@if ($r->isVoided())
    <div class="voided">RESTITUTION ANNULÉE LE {{ $r->voided_at->timezone('Africa/Dakar')->format('d/m/Y') }} — {{ $r->void_reason }}</div>
@endif

@include('pdf.entete', [
    'lh'       => \App\Support\Letterhead::for($structure),
    'numero'   => 'Décharge N° ' . $r->reference,
    'lieuDate' => \App\Support\Letterhead::city($structure) . ', le ' . \App\Support\Letterhead::longDate($at),
])
<div class="title">DÉCHARGE DE RESTITUTION</div>
<div class="subtitle muted">Remise d'un document trouvé à son titulaire{{ $r->isProxy() ? ' par l\'intermédiaire d\'un mandataire' : '' }}</div>

<div class="section">Document restitué</div>
<table class="facts">
    <tr><td class="label">Nature</td><td>{{ $document->documentType?->TypeName ?? 'Document' }}</td></tr>
    <tr><td class="label">Au nom de</td><td>{{ $document->OwnerFirstName }} {{ $document->OwnerLastName }}</td></tr>
    <tr><td class="label">Numéro (4 derniers)</td><td>{{ $mask($document->doc_number_last4) }}</td></tr>
    <tr><td class="label">Référence DocTrack</td><td>{{ $document->reference }}</td></tr>
    @if ($r->declaration)
        <tr><td class="label">Déclaration de perte</td><td>{{ $r->declaration->reference }}</td></tr>
    @endif
</table>

<div class="section">Titulaire</div>
<table class="facts">
    <tr><td class="label">Nom et prénom</td><td>{{ $r->owner_last_name }} {{ $r->owner_first_name }}</td></tr>
    @if ($r->owner_birth_date)<tr><td class="label">Né(e) le</td><td>{{ $r->owner_birth_date->format('d/m/Y') }}</td></tr>@endif
    @if ($r->owner_id_type)
        <tr><td class="label">Pièce présentée</td><td>{{ \App\Models\Restitution::idTypeLabel($r->owner_id_type) }} n° {{ $mask($r->owner_id_last4) }}</td></tr>
    @endif
</table>

@if ($r->isProxy())
    <div class="section">Mandataire</div>
    <table class="facts">
        <tr><td class="label">Nom et prénom</td><td>{{ $r->proxy_last_name }} {{ $r->proxy_first_name }}</td></tr>
        @if ($r->proxy_relationship)<tr><td class="label">Lien avec le titulaire</td><td>{{ $r->proxy_relationship }}</td></tr>@endif
        <tr><td class="label">Pièce présentée</td><td>{{ \App\Models\Restitution::idTypeLabel($r->proxy_id_type) }} n° {{ $mask($r->proxy_id_last4) }}</td></tr>
        <tr><td class="label">Procuration</td><td>{{ $r->power_of_attorney_ref ?: 'présentée et vérifiée' }}</td></tr>
    </table>
@endif

<p class="para">
    Je soussigné(e), <strong>{{ $r->isProxy() ? $r->proxy_last_name . ' ' . $r->proxy_first_name : $r->owner_last_name . ' ' . $r->owner_first_name }}</strong>,
    @if ($r->isProxy()) agissant pour le compte de <strong>{{ $r->owner_last_name }} {{ $r->owner_first_name }}</strong>, @endif
    reconnais avoir reçu ce jour, des mains de l'agent désigné ci-dessous, le document décrit ci-dessus, en bon état,
    et décharge la structure « {{ $structure->name }} » de sa garde.
</p>

<div class="notice">
    Identité vérifiée par l'agent sur présentation d'une pièce officielle en cours de validité
    (photo conforme{{ $r->isProxy() ? ', procuration vérifiée' : '' }}).
    @if ($r->name_override_reason)
        <br>Dérogation sur le nom accordée par un responsable : {{ $r->name_override_reason }}.
    @endif
    Aucune copie de pièce d'identité n'est conservée par DocTrack.
</div>

<table class="sign">
    <tr>
        <td style="padding-right: 10px;">
            <div class="small muted">Signature du bénéficiaire (précédée de « Lu et approuvé »)</div>
            <div class="signbox"></div>
        </td>
        <td style="padding-left: 10px;">
            <div class="small muted">L'agent : {{ trim(($agent->FirstName ?? '') . ' ' . ($agent->LastName ?? '')) }}@if ($matricule) — Matricule {{ $matricule }}@endif</div>
            <div class="signbox"></div>
        </td>
    </tr>
</table>

<table style="margin-top: 16px;">
    <tr>
        <td style="width: 150px;"><img src="{{ $qrDataUri }}" width="130" height="130" alt="QR"></td>
        <td class="verify">
            <div class="small muted">Vérifier l'authenticité de cette décharge</div>
            <div>{{ $r->verificationUrl() }}</div>
            <div class="small muted" style="margin-top: 6px;">Code de vérification</div>
            <div class="code">{{ $r->formattedVerificationCode() }}</div>
        </td>
    </tr>
</table>
</body>
</html>

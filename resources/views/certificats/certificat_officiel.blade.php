<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Certificat de perte — {{ $certificat->certificat_number }}</title>
    <style>
        @page { margin: 34px 46px 64px 46px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10.5px; color: #1a1a1a; line-height: 1.55; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #555; }
        .small { font-size: 8.8px; }

        .title { text-align: center; margin: 10px 0 18px 0; }
        .title div { display: inline-block; border: 1.5px solid #1a1a1a; padding: 7px 26px; font-size: 16px; font-weight: bold; letter-spacing: 2px; }
        .title small { display: block; font-size: 9.5px; margin-top: 5px; letter-spacing: 0; }

        .para { text-align: justify; margin: 0 0 10px 0; }
        .field { border-bottom: 1px dotted #777; font-weight: bold; padding: 0 3px; }

        .items th { text-align: left; padding: 6px 7px; font-size: 9px; border: 1px solid #333; background: #f1f1f1; text-transform: uppercase; }
        .items td { padding: 6px 7px; border: 1px solid #333; font-size: 10px; }

        .closing { margin-top: 12px; font-weight: bold; text-align: justify; }
        .done { text-align: right; margin: 14px 0 6px 0; }

        .sign td { vertical-align: top; }
        .box { border: 1px solid #888; height: 74px; text-align: center; color: #888; font-size: 8.5px; padding-top: 30px; }
        .who { text-align: center; font-weight: bold; text-decoration: underline; margin-bottom: 4px; }

        .notice { margin-top: 18px; padding: 7px 10px; border: 1px solid #bbb; font-size: 8.8px; color: #333; }
        .verify td { vertical-align: middle; }
        .code { font-size: 11.5px; font-weight: bold; letter-spacing: 1px; }

        .revoked { color: #b3261e; border: 2px solid #b3261e; padding: 6px; text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 10px; }
        .footer { position: fixed; bottom: -44px; left: 0; right: 0; font-size: 7.8px; color: #555; border-top: 1px solid #bbb; padding-top: 5px; text-align: center; }
    </style>
</head>
<body>
@php
    $citizen   = $declaration->citizenProfile;
    // Déclaration en ligne certifiée au guichet : la personne présente est celle dont l'agent a vérifié l'identité.
    $certifiedOnline = $declaration->certified_at && $declaration->channel === 'online';
    $personLast  = $certifiedOnline && $declaration->certification_last_name ? $declaration->certification_last_name : $citizen?->last_name;
    $personFirst = $certifiedOnline && $declaration->certification_first_name ? $declaration->certification_first_name : $citizen?->first_name;
    $personBirth = $certifiedOnline && $declaration->certification_birth_date ? $declaration->certification_birth_date : $citizen?->birth_date;
    $agentName = $agent ? trim($agent->FirstName . ' ' . $agent->LastName) : null;
    $issued    = $certificat->created_at->timezone('Africa/Dakar');
    $place     = $lh['city'];
    $dotted    = fn ($v, $w = 140) => $v !== null && $v !== '' ? e($v) : '<span style="display:inline-block; width:' . $w . 'px; border-bottom:1px dotted #777;">&nbsp;</span>';
@endphp

<div class="footer">
    Certificat N° {{ $certificat->certificat_number }} — dossier {{ $declaration->reference }} — émis le {{ $issued->format('d/m/Y à H:i') }} via la plateforme DocTrack.
    Authenticité vérifiable sur {{ $certificat->verificationUrl() }}
</div>

@if ($certificat->isRevoked())
    <div class="revoked">CERTIFICAT RÉVOQUÉ LE {{ $certificat->revoked_at->format('d/m/Y') }} — {{ $certificat->revocation_reason }}</div>
@endif

@include('pdf.entete', [
    'lh'       => $lh,
    'numero'   => 'N° ' . $certificat->certificat_number,
    'lieuDate' => $place . ', le ' . \App\Support\Letterhead::longDate($issued),
])

<div class="title">
    <div>CERTIFICAT DE PERTE</div>
    <small>(déclaration de perte de pièce(s) ou de document(s))</small>
</div>

<p class="para">
    Nous, {!! $agentName ? '<span class="field">' . e($agentName) . '</span>' : $dotted(null, 180) !!}@if ($matricule), matricule <span class="field">{{ $matricule }}</span>@endif,
    en service au <strong>{{ $structure->name }}</strong>,
</p>
<p class="para">
    certifions que M. / Mme <span class="field">{{ mb_strtoupper($personLast ?? '') }} {{ $personFirst }}</span>,
    né(e) le {!! $personBirth ? '<span class="field">' . $personBirth->format('d/m/Y') . '</span>' : $dotted(null, 90) !!},
    s'est présenté(e) ce jour à notre service et a déclaré avoir perdu le(s) document(s) désigné(s) ci-après :
</p>
@if ($certifiedOnline)
<p class="para" style="font-size: 9.5px;">
    Déclaration initialement enregistrée en ligne sur DocTrack le {{ $declaration->created_at->timezone('Africa/Dakar')->format('d/m/Y') }}
    (référence {{ $declaration->reference }}), identité du déclarant vérifiée au guichet.
</p>
@endif

<table class="items">
    <thead>
        <tr>
            <th style="width: 5%;">N°</th>
            <th style="width: 27%;">Nature du document</th>
            <th style="width: 32%;">Au nom de</th>
            <th style="width: 15%;">Né(e) le</th>
            <th style="width: 21%;">Numéro</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($declaration->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->documentType?->TypeName }}</td>
                <td>{{ mb_strtoupper($item->last_name) }} {{ $item->first_name }}</td>
                <td>{{ $item->birth_date_in_doc?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $item->docNumber() ?? 'Non connu' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="para" style="margin-top: 12px;">
    Perte survenue le {!! $declaration->lost_at ? '<span class="field">' . $declaration->lost_at->format('d/m/Y') . '</span>' : '<em>date non précisée</em>' !!},
    à {!! $declaration->lost_place ? '<span class="field">' . e($declaration->lost_place) . '</span>' : '<em>lieu non précisé</em>' !!}@if ($declaration->territory) ({{ $declaration->territory->name }})@endif.
    @if ($declaration->circumstances)
        <br>Circonstances déclarées : {{ $declaration->circumstances }}
    @endif
</p>

<p class="closing">
    En foi de quoi, le présent certificat lui est délivré pour servir et valoir ce que de droit.
</p>

<div class="done">Fait à {{ $place }}, le {{ \App\Support\Letterhead::longDate($issued) }}</div>

<table class="sign" style="margin-top: 6px;">
    <tr>
        <td style="width: 30%; padding-right: 10px;">
            <div class="who">Le déclarant</div>
            <div class="box" style="border-style: dashed;">Signature</div>
        </td>
        <td style="width: 22%; padding-right: 10px;">
            @if (config('doctrack.letterhead.fiscal_stamp'))
                <div class="who">&nbsp;</div>
                <div class="box">Timbre fiscal</div>
            @endif
        </td>
        <td style="width: 48%;">
            <div class="who">{{ $lh['signatory'] }}</div>
            <div class="box" style="height: 90px; padding-top: 38px;">Cachet et signature</div>
        </td>
    </tr>
</table>

<div class="notice">
    <table class="verify">
        <tr>
            <td style="width: 74px;"><img src="{{ $qrDataUri }}" style="width: 66px; height: 66px;"></td>
            <td style="padding-left: 8px;">
                Le présent certificat atteste uniquement de la déclaration de perte. Il ne constitue ni une pièce d'identité
                ni un document de remplacement. Toute fausse déclaration expose son auteur aux poursuites prévues par la loi.<br>
                <strong>Vérifier l'authenticité :</strong> scannez le QR code ou saisissez le code
                <span class="code">{{ $certificat->formattedVerificationCode() }}</span> sur {{ $certificat->verificationUrl() }}
            </td>
        </tr>
    </table>
</div>

</body>
</html>

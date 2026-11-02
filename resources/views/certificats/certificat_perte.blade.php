<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récépissé de déclaration de perte — {{ $certificat->certificat_number }}</title>
    <style>
        @page { margin: 34px 46px 64px 46px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10.5px; color: #1a1a1a; line-height: 1.55; }
        table { width: 100%; border-collapse: collapse; }
        .title { text-align: center; margin: 10px 0 18px 0; }
        .title div { display: inline-block; border: 1.5px solid #31287c; color: #31287c; padding: 7px 22px; font-size: 15px; font-weight: bold; letter-spacing: 1.5px; }
        .title small { display: block; font-size: 9.5px; margin-top: 5px; color: #555; }
        .para { text-align: justify; margin: 0 0 10px 0; }
        .grid th { text-align: left; width: 34%; padding: 6px 8px; border: 1px solid #333; background: #f1f1f1; font-size: 9.5px; }
        .grid td { padding: 6px 8px; border: 1px solid #333; }
        .warn { margin-top: 16px; padding: 8px 11px; border: 1.5px solid #c98400; background: #fff8e8; font-size: 9.5px; }
        .steps { margin: 6px 0 0 16px; padding: 0; }
        .done { text-align: right; margin: 16px 0 0 0; }
        .footer { position: fixed; bottom: -44px; left: 0; right: 0; font-size: 7.8px; color: #555; border-top: 1px solid #bbb; padding-top: 5px; text-align: center; }
    </style>
</head>
<body>
@php
    $d         = $certificat->declarationDePerte;
    $declarant = $d?->user;
    $created   = ($d?->created_at ?? $certificat->created_at)->timezone('Africa/Dakar');
    $number    = $d?->DocIdentification;
    $masked    = $number ? str_repeat('•', max(0, mb_strlen($number) - 4)) . mb_substr($number, -4) : null;
@endphp

<div class="footer">
    Récépissé N° {{ $certificat->certificat_number }} — émis automatiquement par la plateforme DocTrack le {{ $certificat->created_at->timezone('Africa/Dakar')->format('d/m/Y à H:i') }}
    @if ($certificat->verification_code) — vérification : {{ $certificat->verificationUrl() }} @endif
</div>

@include('pdf.entete', [
    'lh'       => ['lines' => [config('doctrack.letterhead.platform'), 'Service des déclarations de perte en ligne']],
    'etat'     => false,
    'numero'   => 'N° ' . $certificat->certificat_number,
    'lieuDate' => config('doctrack.letterhead.default_city', 'Dakar') . ', le ' . \App\Support\Letterhead::longDate($certificat->created_at),
])

<div class="title">
    <div>RÉCÉPISSÉ DE DÉCLARATION DE PERTE</div>
    <small>Déclaration enregistrée en ligne — document non officiel</small>
</div>

<p class="para">
    La plateforme DocTrack atteste que
    @if ($declarant) <strong>{{ mb_strtoupper($declarant->LastName) }} {{ $declarant->FirstName }}</strong> @else le déclarant @endif
    a enregistré en ligne, le <strong>{{ \App\Support\Letterhead::longDate($created) }}</strong> à {{ $created->format('H:i') }},
    une déclaration de perte concernant le document suivant :
</p>

<table class="grid">
    <tr><th>Nature du document</th><td>{{ $certificat->documentType->TypeName ?? 'Non précisé' }}</td></tr>
    <tr><th>Au nom de</th><td>{{ mb_strtoupper($d?->LastNameInDoc ?? '') }} {{ $d?->FirstNameInDoc }}</td></tr>
    <tr><th>Numéro du document</th><td>{{ $masked ?? 'Non renseigné' }}</td></tr>
    @if ($d?->reference)<tr><th>Référence de la déclaration</th><td>{{ $d->reference }}</td></tr>@endif
</table>

<p class="para" style="margin-top: 12px;">
    Dès qu'un document correspondant est publié ou déposé dans une structure, le déclarant est prévenu.
</p>

<div class="warn">
    <strong>Ce récépissé n'est pas un certificat de perte.</strong> Il prouve seulement l'enregistrement de la déclaration sur DocTrack.
    Les informations ont été saisies par le déclarant et n'ont pas été vérifiées par une autorité.
    Pour obtenir le certificat de perte (demandé pour un duplicata ou un renouvellement) :
    <ol class="steps">
        <li>présentez-vous en personne dans un commissariat de police ou une brigade de gendarmerie partenaire de DocTrack ;</li>
        <li>munissez-vous de ce récépissé (ou de sa référence) et d'une pièce justifiant de votre identité (passeport, permis, extrait de naissance…) ;</li>
        <li>l'agent retrouve votre déclaration dans DocTrack grâce à la référence ou au QR code ci-dessous, vérifie votre identité et vous délivre le certificat de perte.</li>
    </ol>
</div>

@if (! empty($qrDataUri))
<table style="margin-top: 12px; border-collapse: collapse;">
    <tr>
        <td style="width: 74px; vertical-align: middle;"><img src="{{ $qrDataUri }}" style="width: 66px; height: 66px;"></td>
        <td style="padding-left: 8px; font-size: 9px; color: #333; vertical-align: middle;">
            À présenter au guichet. Code de vérification : <strong>{{ $certificat->formattedVerificationCode() }}</strong><br>
            Vérifiable sur {{ $certificat->verificationUrl() }}
        </td>
    </tr>
</table>
@endif

<div class="done">Fait à {{ config('doctrack.letterhead.default_city', 'Dakar') }}, le {{ \App\Support\Letterhead::longDate($certificat->created_at) }}<br>
    <span style="font-size: 9px; color: #555;">Document généré automatiquement, sans signature.</span></div>

</body>
</html>

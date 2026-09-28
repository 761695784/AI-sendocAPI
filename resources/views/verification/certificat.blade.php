<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Vérification de certificat — DocTrack</title>
    <style>
        :root { --ok: #1b7f3b; --ko: #b3261e; --warn: #9a6700; --ink: #1f2430; --muted: #5b6272; --line: #dde0e6; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #f4f5f7; }
        main { max-width: 520px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 999px; font-weight: 600; font-size: 14px; }
        .ok { background: #e6f4ea; color: var(--ok); }
        .ko { background: #fce8e6; color: var(--ko); }
        .warn { background: #fff4d6; color: var(--warn); }
        h1 { font-size: 18px; margin: 14px 0 4px; }
        p { margin: 6px 0; }
        dl { margin: 16px 0 0; display: grid; grid-template-columns: 40% 60%; row-gap: 8px; font-size: 14px; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        .foot { color: var(--muted); font-size: 12px; margin-top: 16px; text-align: center; }
        code { font-size: 13px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if (! $s['found'])
            <span class="badge ko">Certificat introuvable</span>
            <h1>Aucun certificat ne correspond à ce code</h1>
            <p>Vérifiez le code imprimé sous le QR code (<code>{{ $code }}</code>). Un document dont le code est introuvable ne doit pas être considéré comme authentique.</p>
        @elseif ($s['status'] === 'revoked')
            <span class="badge ko">{{ ($s['type'] ?? '') === 'restitution' ? 'Décharge annulée' : 'Certificat révoqué' }}</span>
            <h1>{{ ($s['type'] ?? '') === 'restitution' ? 'Cette décharge n\'est plus valable' : 'Ce certificat n\'est plus valable' }}</h1>
            <p>Révoqué le {{ \Carbon\Carbon::parse($s['revoked_at'])->format('d/m/Y') }} — {{ $s['revocation_reason'] }}</p>
        @elseif (($s['type'] ?? '') === 'restitution')
            <span class="badge ok">Décharge authentique</span>
            <h1>{{ $s['kind_label'] }}</h1>
        @elseif ($s['kind'] === 'official')
            <span class="badge ok">Certificat authentique</span>
            <h1>{{ $s['kind_label'] }}</h1>
        @else
            <span class="badge warn">Récépissé en ligne</span>
            <h1>{{ $s['kind_label'] }}</h1>
        @endif

        @if ($s['found'])
            <dl>
                <dt>Numéro</dt><dd>{{ $s['certificate_number'] }}</dd>
                <dt>{{ ($s['type'] ?? '') === 'restitution' ? 'Remis le' : 'Délivré le' }}</dt><dd>{{ \Carbon\Carbon::parse($s['issued_at'])->format('d/m/Y') }}</dd>
                @if ($s['issued_by'])
                    <dt>Délivré par</dt><dd>{{ $s['issued_by']['structure'] }}<br><small>{{ $s['issued_by']['institution'] }}</small></dd>
                @endif
                <dt>Document(s)</dt><dd>{{ implode(', ', $s['document_types']) }}</dd>
                <dt>Titulaire</dt><dd>{{ $s['holder_initials'] }}</dd>
            </dl>
        @endif
    </div>
    <p class="foot">Vérification DocTrack — seules les informations nécessaires à l'authentification sont affichées.</p>
</main>
</body>
</html>

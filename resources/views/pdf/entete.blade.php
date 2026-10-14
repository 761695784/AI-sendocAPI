{{--
    En-tête administratif sénégalais (partagé : certificat de perte, décharge, rapports).
    Paramètres : $lh (Letterhead::for), $numero (ligne « N° … », facultatif), $lieuDate (« Dakar, le … », facultatif),
    $etat (bool, défaut true : afficher « République du Sénégal » et la devise ; false pour un document de la plateforme).
--}}
@php $etat = $etat ?? true; @endphp
<table class="lh" style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
    <tr>
        <td style="width: 56%; vertical-align: top; text-align: center; padding-right: 14px;">
            @if ($etat)
                <div style="font-size: 11.5px; font-weight: bold; letter-spacing: .6px;">RÉPUBLIQUE DU SÉNÉGAL</div>
                <div style="font-size: 9.5px; font-style: italic; margin-top: 1px;">Un Peuple – Un But – Une Foi</div>
                <div style="width: 70px; margin: 5px auto 6px auto; border-top: 1px solid #1f2430;"></div>
            @endif
            @foreach ($lh['lines'] as $i => $line)
                <div style="font-size: {{ $i === count($lh['lines']) - 1 ? '10.5' : '9.5' }}px; font-weight: bold; text-transform: uppercase; line-height: 1.35;">{{ $line }}</div>
                @if (! $loop->last)<div style="font-size: 8px; line-height: 1;">—</div>@endif
            @endforeach
            @if (! empty($numero))
                <div style="font-size: 9.5px; margin-top: 7px; text-align: left;">{{ $numero }}</div>
            @endif
        </td>
        <td style="width: 44%; vertical-align: top; text-align: right;">
            @if ($etat)
                {{-- Drapeau du Sénégal : vert, or, rouge, étoile verte au centre --}}
                <table style="width: 48px; border-collapse: collapse; margin-left: auto; margin-bottom: 18px;">
                    <tr>
                        <td style="width: 16px; height: 24px; background: #00853F;"></td>
                        <td style="width: 16px; height: 24px; background: #FDEF42; color: #00853F; font-size: 12px; text-align: center; vertical-align: middle; line-height: 1;">★</td>
                        <td style="width: 16px; height: 24px; background: #E31B23;"></td>
                    </tr>
                </table>
            @endif
            @if (! empty($lieuDate))
                <div style="font-size: 10.5px;">{{ $lieuDate }}</div>
            @endif
        </td>
    </tr>
</table>

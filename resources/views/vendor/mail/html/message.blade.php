@php
    $site = rtrim(config('doctrack.frontend_url'), '/');
    $siteLabel = parse_url($site, PHP_URL_HOST) ?: $site;
@endphp
<x-mail::layout>
{{-- En-tête : bandeau violet avec le logo DocTrack --}}
<x-slot:header>
<x-mail::header :url="$site">
DocTrack
</x-mail::header>
</x-slot:header>

{{-- Contenu --}}
{{ $slot }}

{{-- Texte secondaire (lien de secours du bouton) --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Pied de page --}}
<x-slot:footer>
<x-mail::footer>
**DocTrack** · Retrouver ses documents perdus au Sénégal<br>
[{{ $siteLabel }}]({{ $site }}) · [Vérifier un certificat]({{ $site }}/verifier)

DocTrack ne vous demandera jamais d'argent pour vous rendre un document. En cas de doute, rendez-vous au commissariat ou à la brigade la plus proche.

© {{ date('Y') }} DocTrack · Majeli Connect
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

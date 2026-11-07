{{-- Modèle commun des notifications DocTrack (MailMessage) : salutation, bouton et lien de secours en français. --}}
<x-mail::message>
{{-- Salutation --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# Attention
@else
# Bonjour,
@endif

{{-- Paragraphes d'introduction --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Bouton d'action --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Paragraphes de conclusion --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Formule de fin --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Cordialement,<br>
**L'équipe DocTrack**
@endif

{{-- Lien de secours si le bouton ne s'affiche pas --}}
@isset($actionText)
<x-slot:subcopy>
Si le bouton « {{ $actionText }} » ne fonctionne pas, copiez ce lien dans votre navigateur :
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

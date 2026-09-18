@component('mail::message')
# Nouveau commentaire sur votre publication

Bonjour **{{ $auteur->FirstName }}**,

Votre avez reçu un nouveau commentaire dans votre publication du document perdu

{{-- @component('mail::panel')
"{{ $commentaire->contenu }}"
@endcomponent --}}

Nous vous encourageons à cliquer sur le lien ci-dessous pour voir votre publication et répondre au commentaire.

@component('mail::button', ['url' => $documentUrl])
Voir la publication
@endcomponent

Merci,
L'équipe de la plateforme
{{ config('app.name') }}
@endcomponent

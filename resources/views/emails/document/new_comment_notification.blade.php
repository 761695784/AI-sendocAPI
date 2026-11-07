@component('mail::message')
# Nouveau commentaire sur votre publication

Bonjour **{{ $auteur->FirstName }}**,

Vous avez reçu un nouveau commentaire sur votre publication de document trouvé.

{{-- @component('mail::panel')
"{{ $commentaire->contenu }}"
@endcomponent --}}

Cliquez sur le bouton ci-dessous pour voir votre publication et répondre au commentaire.

@component('mail::button', ['url' => $documentUrl])
Voir la publication
@endcomponent

Cordialement,<br>
**L'équipe DocTrack**
@endcomponent

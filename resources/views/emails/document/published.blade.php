@component('mail::message')
# Un document qui pourrait être le vôtre a été publié

Bonjour{{ $recipientName ? ' ' . $recipientName : '' }},

Suite à votre déclaration de perte, nous avons le plaisir de vous informer que le document portant le prénom **{{ $document->OwnerFirstName }}** et le nom **{{ $document->OwnerLastName }}** a été récemment publié. Il pourrait correspondre à votre déclaration de perte.

Cliquez sur le bouton ci-dessous pour voir la publication et, si c'est bien votre document, demander sa restitution.

@component('mail::button', ['url' => $documentUrl])
Voir la publication
@endcomponent

Nous vous remercions de votre attention et restons à votre disposition pour toute assistance complémentaire.

Cordialement,<br>
L'équipe Sénégal DocTrack
@endcomponent

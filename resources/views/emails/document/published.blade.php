@component('mail::message')
# Notification de Publication d'un Document

Bonjour {{ $document->FirstName }} ,

Suite à votre déclaration de perte, nous avons le plaisir de vous informer que le document portant le prénom **{{ $document->OwnerFirstName }}** et le nom **{{ $document->OwnerLastName }}** a été récemment publié. Il pourrait correspondre à votre déclaration de perte.

Nous vous encourageons à clicquer sur le lien ci dessous pour suivre le processus de la demande de restitution .

@component('mail::button', ['url' => $documentUrl]) <!-- Assurez-vous que $documentUrl est bien passé -->
Voir la publication
@endcomponent

Nous vous remercions de votre attention et restons à votre disposition pour toute assistance complémentaire.

Cordialement,<br>
{{ config('app.name') }}
@endcomponent

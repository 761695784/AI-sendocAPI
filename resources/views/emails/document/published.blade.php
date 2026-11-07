@component('mail::message')
# Un document qui pourrait être le vôtre a été publié

Bonjour{{ $recipientName ? ' ' . $recipientName : '' }},

Suite à votre déclaration de perte, un document correspondant à votre nom vient d'être publié sur DocTrack :

@component('mail::panel')
Prénom : **{{ $document->OwnerFirstName }}**<br>
Nom : **{{ $document->OwnerLastName }}**
@endcomponent

Consultez la publication : si c'est bien votre document, vous pourrez demander sa restitution en quelques clics.

@component('mail::button', ['url' => $documentUrl, 'color' => 'success'])
Voir la publication
@endcomponent

Cordialement,<br>
**L'équipe DocTrack**
@endcomponent

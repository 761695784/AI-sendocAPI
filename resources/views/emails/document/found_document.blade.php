@component('mail::message')
# Votre document a été trouvé

Bonjour {{ $document->FirstName }},

Bonne nouvelle : une personne a trouvé votre document et l'a signalé sur DocTrack. Vous pouvez la joindre au numéro suivant :

@component('mail::panel')
**{{ $finderPhone }}**
@endcomponent

Pour votre sécurité, convenez d'un rendez-vous dans un lieu public, de préférence en journée. La remise d'un document est toujours gratuite.

Cordialement,<br>
**L'équipe DocTrack**
@endcomponent

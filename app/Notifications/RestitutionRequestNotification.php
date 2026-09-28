<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\EmailLog;
use App\Models\RestitutionRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Au trouveur : le propriétaire confirmé demande son document.
 * Double accord : AUCUNE coordonnée du demandeur ici ; elles ne sont
 * transmises qu'après l'acceptation du trouveur.
 */
class RestitutionRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public User $fromUser,
        public Document $document,
        public bool $confirmedOwner = true,
        public ?RestitutionRequest $request = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url   = rtrim(config('doctrack.frontend_url'), '/') . ($this->request ? '/restitutions/' . $this->request->uuid : '/document/' . $this->document->uuid);
        $hours = config('doctrack.restitution.response_hours', 48);

        $mail = (new MailMessage)
            ->subject('Demande de restitution')
            ->greeting('Bonjour ' . $notifiable->FirstName . ',')
            ->line('Le propriétaire du document au nom de ' . $this->document->OwnerFirstName . ' ' . $this->document->OwnerLastName
                . ' que vous avez publié souhaite le récupérer.')
            ->line('DocTrack a confirmé que ce document correspond à sa déclaration de perte.')
            ->line('Acceptez-vous d\'être mis en contact avec lui ? Si vous acceptez, vous recevrez chacun le numéro de l\'autre.')
            ->action('Répondre à la demande', $url)
            ->line('Sans réponse sous ' . $hours . ' h, la structure de police ou de gendarmerie la plus proche prendra le relais.')
            ->line('L\'équipe Sénégal DocTrack');

        // Journal conservé pour les statistiques existantes (sans coordonnées).
        EmailLog::create([
            'from'              => config('mail.from.address'),
            'to'                => $notifiable->email,
            'subject'           => $mail->subject,
            'body'              => implode("\n", $mail->introLines),
            'publisher_user_id' => $notifiable->id,
            'requester_user_id' => $this->fromUser->id,
            'document_id'       => $this->document->uuid,
            'declarant_user_id' => null,
        ]);

        return $mail;
    }
}

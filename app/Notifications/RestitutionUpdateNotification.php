<?php

namespace App\Notifications;

use App\Models\RestitutionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Suite d'une demande de restitution directe (email).
 */
class RestitutionUpdateNotification extends Notification
{
    use Queueable;

    public const CONTACT_FOR_OWNER  = 'contact_for_owner';  // le propriétaire reçoit le contact du trouveur
    public const CONTACT_FOR_FINDER = 'contact_for_finder'; // le trouveur reçoit le contact du propriétaire
    public const DECLINED           = 'declined';
    public const EXPIRED            = 'expired';

    public function __construct(public RestitutionRequest $request, public string $event, public ?string $detail = null) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $doc  = $this->request->document;
        $what = 'le document au nom de ' . $doc->OwnerFirstName . ' ' . $doc->OwnerLastName;
        $mail = (new MailMessage)->greeting('Bonjour ' . $notifiable->FirstName . ',');

        return match ($this->event) {
            self::CONTACT_FOR_OWNER => $mail->subject('Le trouveur accepte de vous remettre votre document')
                ->line('Le trouveur accepte de vous remettre ' . $what . '.')
                ->line('Contactez ' . $this->request->finder->FirstName . ' au ' . $this->detail . ' pour convenir d\'un lieu de remise.')
                ->line('Conseils : rencontrez-vous dans un lieu public, vérifiez la pièce, ne payez jamais pour récupérer un document.'),
            self::CONTACT_FOR_FINDER => $mail->subject('Mise en contact avec le propriétaire')
                ->line('Merci ! Le propriétaire (' . $this->request->requester->FirstName . ', ' . $this->detail . ') va vous contacter pour récupérer ' . $what . '.')
                ->line('Une fois le document remis, indiquez-le sur DocTrack (« Document restitué »).'),
            default => $mail->subject('Votre demande de restitution')
                ->line('Le trouveur n\'a pas ' . ($this->event === self::EXPIRED ? 'répondu dans le délai' : 'accepté la remise directe') . '.')
                ->line((string) $this->detail),
        };
    }
}

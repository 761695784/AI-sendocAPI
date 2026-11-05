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
 * Au trouveur : le propriétaire réclame le document publié.
 * Le numéro du demandeur est donné pour que le trouveur puisse l'appeler s'il le souhaite
 * (le demandeur a, de son côté, reçu le numéro du trouveur après avoir accepté les conditions).
 */
class RestitutionRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public User $fromUser,
        public Document $document,
        public bool $confirmedOwner = true,
        public ?RestitutionRequest $request = null,
        public ?string $ownerPhone = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url   = rtrim(config('doctrack.frontend_url'), '/') . ($this->request ? '/mon-espace/restitutions?demande=' . $this->request->uuid : '/document/' . $this->document->uuid);
        $mail = (new MailMessage)
            ->subject('Demande de restitution — document au nom de ' . $this->document->OwnerFirstName . ' ' . $this->document->OwnerLastName)
            ->greeting('Bonjour ' . $notifiable->FirstName . ',')
            ->line('Une personne réclame le document au nom de ' . $this->document->OwnerFirstName . ' ' . $this->document->OwnerLastName
                . ' que vous avez publié sur DocTrack. Merci pour votre geste !')
            ->line($this->confirmedOwner
                ? 'DocTrack a confirmé que ce document correspond à sa déclaration de perte.'
                : 'Elle a indiqué être le titulaire de ce document. Vérifiez sa pièce d\'identité (nom et photo) avant de le lui remettre.');

        if ($this->request?->status === RestitutionRequest::STATUS_PENDING) {
            // Identité non confirmée automatiquement : le trouveur compare et décide.
            $mail->line('Nom indiqué par la personne : ' . trim($this->fromUser->FirstName . ' ' . $this->fromUser->LastName)
                . '. Il diffère un peu de celui du document (deux noms de famille, nom d\'épouse, numéro non saisi…) : comparez avec le document que vous avez.')
                ->line('Si vous acceptez, vous recevrez chacun le numéro de l\'autre. Si vous refusez ou ne répondez pas sous '
                    . config('doctrack.restitution.response_hours', 48) . ' h, la structure de police ou de gendarmerie la plus proche prendra le relais.')
                ->action('Répondre à la demande', $url)
                ->line('L\'équipe Sénégal DocTrack');

            $this->log($notifiable, $mail);

            return $mail;
        }

        if ($this->ownerPhone) {
            $mail->line('Ses coordonnées : ' . $this->fromUser->FirstName . ' — ' . $this->ownerPhone . '. Elle a aussi reçu votre numéro et va vous contacter ; vous pouvez l\'appeler si vous le souhaitez.');
        }

        $mail->line('Conseils : rencontrez-vous dans un lieu public et fréquenté, en journée. Vous n\'avez rien à payer ni à demander. '
                . 'Si vous préférez, déposez le document au commissariat ou à la brigade la plus proche.')
            ->action('Voir la demande', $url)
            ->line('Une fois le document remis, ouvrez « Mes publications » et marquez-la « Récupéré ».')
            ->line('L\'équipe Sénégal DocTrack');

        $this->log($notifiable, $mail);

        return $mail;
    }

    /** Journal conservé pour les statistiques existantes (sans coordonnées). */
    private function log($notifiable, MailMessage $mail): void
    {
        EmailLog::create([
            'from'              => config('mail.from.address'),
            'to'                => $notifiable->email,
            'subject'           => $mail->subject,
            'body'              => implode("\n", array_slice($mail->introLines, 0, 2)), // sans la ligne des coordonnées
            'publisher_user_id' => $notifiable->id,
            'requester_user_id' => $this->fromUser->id,
            'document_id'       => $this->document->uuid,
            'declarant_user_id' => null,
        ]);
    }
}

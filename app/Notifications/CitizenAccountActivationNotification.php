<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Compte créé au guichet : lien pour choisir son mot de passe (valable 7 jours). */
class CitizenAccountActivationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token, public ?string $structure = null) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url  = rtrim(config('doctrack.frontend_url'), '/') . '/newpass?activation=1&token=' . $this->token . '&email=' . urlencode($notifiable->email);
        $days = (int) round(config('auth.passwords.activations.expire', 10080) / 1440);

        return (new MailMessage)
            ->subject('Votre compte DocTrack a été créé')
            ->greeting('Bonjour ' . $notifiable->FirstName . ',')
            ->line(($this->structure ? 'Le ' . $this->structure : 'Une structure de police ou de gendarmerie')
                . ' a enregistré votre déclaration de perte et vous a créé un compte DocTrack.')
            ->line('Choisissez votre mot de passe pour suivre vos déclarations, télécharger votre certificat et être prévenu dès qu\'un document correspondant est retrouvé.')
            ->action('Choisir mon mot de passe', $url)
            ->line('Ce lien est valable ' . $days . ' jours. Passé ce délai, utilisez « Mot de passe oublié » avec cette adresse email.')
            ->line('L\'équipe Sénégal DocTrack');
    }
}

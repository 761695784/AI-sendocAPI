<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends Notification
{
    use Queueable;

    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Détermine les canaux de notification.
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Crée le contenu du mail.
     */
    public function toMail($notifiable)
    {
        // Générer l'URL de redirection vers le frontend
        // $frontendUrl = "http://localhost:4200/newpass?token={$this->token}&email=" . urlencode($notifiable->getEmailForPasswordReset()); lien pour le serveur en local
        $frontendUrl = "https://sendoctrack.netlify.app/newpass?token={$this->token}&email=" . urlencode($notifiable->getEmailForPasswordReset()); // lien du site deployé

        return (new MailMessage)
            ->subject(Lang::get('Notification de réinitialisation de mot de passe'))
            ->line(Lang::get('Vous recevez cet email car nous avons reçu une demande de réinitialisation de mot de passe pour votre compte.'))
            ->action(Lang::get('Réinitialiser le mot de passe'), $frontendUrl)
            ->line(Lang::get('Si vous n\'avez pas demandé de réinitialisation de mot de passe, aucune autre action n\'est requise.'));
    }

}

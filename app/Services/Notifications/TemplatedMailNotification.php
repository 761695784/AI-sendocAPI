<?php

namespace App\Services\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email simple construit à partir d'un modèle de config/notifications.php. */
class TemplatedMailNotification extends Notification
{
    use Queueable;

    public function __construct(public string $subject, public array $lines) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Bonjour,');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->line('L\'équipe Sénégal DocTrack');
    }
}

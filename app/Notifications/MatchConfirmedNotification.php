<?php

namespace App\Notifications;

use App\Models\DocumentMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Au propriétaire : sa pièce est retrouvée (correspondance confirmée).
 *  - document chez un citoyen  → il peut demander la restitution au trouveur ;
 *  - document dans une structure → il s'y présente avec une pièce justificative.
 */
class MatchConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(public DocumentMatch $match) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $doc     = $this->match->document;
        $custody = $doc->custodyOrganization;
        $mail    = (new MailMessage)
            ->subject('Votre document a été retrouvé')
            ->greeting('Bonjour ' . $notifiable->FirstName . ',')
            ->line('DocTrack a confirmé qu\'un document trouvé correspond à votre déclaration de perte'
                . ($this->match->declaration?->reference ? ' (' . $this->match->declaration->reference . ')' : '') . '.')
            ->line('Document : ' . ($doc->documentType?->TypeName ?? 'pièce') . ' au nom de ' . $doc->OwnerFirstName . ' ' . $doc->OwnerLastName . '.');

        if ($custody) {
            return $mail
                ->line('Il est conservé par : ' . $custody->name . ($custody->address ? ', ' . $custody->address : '') . ($custody->phone ? ' — tél. ' . $custody->phone : '') . '.')
                ->line('Présentez-vous avec une pièce justificative et la référence ' . $doc->reference . '.');
        }

        return $mail
            ->line('Il a été trouvé par un citoyen. Vous pouvez lui demander la restitution depuis DocTrack : il recevra votre demande par SMS et par email.')
            ->action('Demander la restitution', rtrim(config('doctrack.frontend_url'), '/') . '/document/' . $doc->uuid);
    }
}

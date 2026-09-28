<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envoi d'un SMS hors de la requête ; le résultat est reporté dans le
 * journal des envois. Une panne de l'opérateur ne bloque jamais l'action.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public ?string $phone, public string $message, public ?int $deliveryId = null) {}

    public function handle(SmsService $sms): void
    {
        $delivery = $this->deliveryId ? NotificationDelivery::find($this->deliveryId) : null;
        $delivery?->increment('attempts');

        if ($sms->sendSMS($this->phone, $this->message)) {
            $delivery?->markSent();
        } else {
            $delivery?->markFailed('Échec de l\'envoi (numéro invalide, identifiants ou opérateur) — voir les logs');
        }
    }
}

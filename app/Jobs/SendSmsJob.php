<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envoi d'un SMS hors de la requête : une panne de l'opérateur ne bloque
 * jamais l'action de l'utilisateur (SmsService ne lève pas d'exception).
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public ?string $phone, public string $message) {}

    public function handle(SmsService $sms): void
    {
        $sms->sendSMS($this->phone, $this->message);
    }
}

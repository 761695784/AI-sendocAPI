<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyDeclarantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public Document $document,
        public User     $declarant,
        public string   $documentUrl,
        public int      $declarationId,
    ) {}

public function handle(
    EmailNotificationService $emailService,
    SmsService $smsService
): void {
    // ── Recharge depuis la DB avec toutes les relations nécessaires ──
    $document  = $this->document->fresh(['user', 'documentType', 'media']);
    $declarant = $this->declarant->fresh();

    if (!$document || !$declarant) {
        Log::warning('NotifyDeclarantJob: document ou déclarant introuvable.');
        return;
    }

    try {
        $emailService->notifyDeclarant(
            $document,          // ← maintenant $document->user est chargé
            $declarant,
            $declarant->Phone,
            $this->documentUrl
        );
    } catch (\Exception $e) {
        Log::error('NotifyDeclarantJob MAIL: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
        throw $e;
    }

    try {
        $smsService->sendSMS(
            $declarant->Phone,
            'Un document correspondant à votre déclaration a été trouvé : ' .
            $document->OwnerFirstName . ' ' . $document->OwnerLastName .
            '. Consultez-le ici : ' . $this->documentUrl
        );
    } catch (\Exception $e) {
        Log::warning('NotifyDeclarantJob SMS (non bloquant): ' . $e->getMessage());
    }

    try {
        Notification::create([
            'message'                 => 'Un document correspondant a été trouvé : ' .
                                        $document->OwnerFirstName . ' ' .
                                        $document->OwnerLastName,
            'is_read'                 => false,
            'declaration_de_perte_id' => $this->declarationId,
        ]);
    } catch (\Exception $e) {
        Log::warning('NotifyDeclarantJob Notification: ' . $e->getMessage());
    }
}
}

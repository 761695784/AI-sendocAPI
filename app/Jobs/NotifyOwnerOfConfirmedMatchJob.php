<?php

namespace App\Jobs;

use App\Http\Controllers\DocumentController;
use App\Models\DocumentMatch;
use App\Models\Notification;
use App\Notifications\MatchConfirmedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Correspondance confirmée → le propriétaire est prévenu (email + SMS),
 * une seule fois par correspondance.
 */
class NotifyOwnerOfConfirmedMatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $matchId) {}

    public function handle(): void
    {
        $match = DocumentMatch::with(['declaration.user', 'declaration.citizenProfile', 'document.documentType', 'document.custodyOrganization'])
            ->find($this->matchId);

        if (! $match || $match->status !== DocumentMatch::STATUS_CONFIRMED || $match->notified_at || ! $match->document || ! $match->declaration) {
            return;
        }

        $declaration = $match->declaration;
        $document    = $match->document;
        $owner       = $declaration->user;
        $phone       = $owner?->Phone ?: $declaration->citizenProfile?->phone();
        $custody     = $document->custodyOrganization;

        try {
            if ($owner?->email) {
                $owner->notify(new MatchConfirmedNotification($match));
            }
        } catch (\Throwable $e) {
            Log::warning('Email correspondance confirmée non envoyé', ['match' => $match->uuid, 'error' => $e->getMessage()]);
        }

        $sms = 'DocTrack : votre ' . ($document->documentType?->TypeName ?? 'document') . ' au nom de '
            . $document->OwnerFirstName . ' ' . $document->OwnerLastName . ' a été retrouvé. ';
        $sms .= $custody
            ? 'Il est conservé par ' . $custody->name . ($custody->phone ? ' (tél. ' . $custody->phone . ')' : '') . '. Réf. ' . $document->reference . '.'
            : 'Demandez la restitution au trouveur sur ' . DocumentController::frontendDocumentUrl($document);
        SendSmsJob::dispatch($phone, $sms);

        if ($declaration->user_id) {
            Notification::create([
                'message'                 => 'Correspondance confirmée : ' . $document->OwnerFirstName . ' ' . $document->OwnerLastName
                    . ($custody ? ' — conservé par ' . $custody->name : ' — vous pouvez demander la restitution'),
                'is_read'                 => false,
                'declaration_de_perte_id' => $declaration->id,
            ]);
        }

        $match->forceFill(['notified_at' => now()])->save();
    }
}

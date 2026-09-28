<?php

namespace App\Jobs;

use App\Http\Controllers\DocumentController;
use App\Models\DocumentMatch;
use App\Models\Notification;
use App\Notifications\MatchConfirmedNotification;
use App\Services\Notifications\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

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

        $notifier = app(Notifier::class);
        $context  = ['subject' => $match, 'organization_id' => $custody?->id, 'recipient' => $owner ?? $declaration->citizenProfile];

        if ($owner) {
            $notifier->mail('match.confirmed', $owner, new MatchConfirmedNotification($match), $context + ['dedupe' => 'match.confirmed.mail:' . $match->id]);
        }

        $params = [
            'document_type'   => $document->documentType?->TypeName ?? 'document',
            'owner_name'      => $document->OwnerFirstName . ' ' . $document->OwnerLastName,
            'structure'       => $custody?->name,
            'structure_phone' => $custody?->phone ? ' (tél. ' . $custody->phone . ')' : '',
            'reference'       => $document->reference,
            'url'             => DocumentController::frontendDocumentUrl($document),
        ];
        $notifier->sms($custody ? 'match.confirmed.custody' : 'match.confirmed.online', $phone, $params,
            $context + ['dedupe' => 'match.confirmed.sms:' . $match->id]);

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

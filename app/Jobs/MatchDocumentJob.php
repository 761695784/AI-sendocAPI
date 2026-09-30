<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Matching\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Recherche des déclarations correspondant à un document trouvé.
 * Un échec du matching ne doit jamais bloquer l'enregistrement du document.
 */
class MatchDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $documentId) {}

    public function handle(MatchingService $matching): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }

        try {
            $matching->matchDocument($document);
        } catch (\Throwable $e) {
            Log::error('Matching document en échec', ['document_id' => $this->documentId, 'error' => $e->getMessage()]);
            report($e);
        }

        // Doublons et signaux de fraude (n'interrompt jamais le traitement).
        app(\App\Services\Vigilance\VigilanceService::class)->checkDocument($document);
    }
}

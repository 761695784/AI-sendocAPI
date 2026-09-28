<?php

namespace App\Jobs;

use App\Models\DeclarationDePerte;
use App\Services\Matching\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Recherche des documents trouvés correspondant à une déclaration.
 */
class MatchDeclarationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $declarationId) {}

    public function handle(MatchingService $matching): void
    {
        $declaration = DeclarationDePerte::find($this->declarationId);
        if (! $declaration) {
            return;
        }

        try {
            $matching->matchDeclaration($declaration);
        } catch (\Throwable $e) {
            Log::error('Matching déclaration en échec', ['declaration_id' => $this->declarationId, 'error' => $e->getMessage()]);
            report($e);
        }
    }
}

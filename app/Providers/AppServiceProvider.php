<?php

namespace App\Providers;

use App\Http\Middleware\ResolveActiveOrganization;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped" : une instance par requête (y compris sous Octane).
        $this->app->scoped(OrganizationAccess::class);

        // Moteur de correspondance : "rules" aujourd'hui ; un moteur IA
        // implémentant MatchingEngine pourra être sélectionné par config.
        $this->app->singleton(\App\Services\Matching\NameNormalizer::class);
        $this->app->bind(\App\Services\Matching\MatchingEngine::class, fn ($app) => match (config('matching.engine')) {
            default => $app->make(\App\Services\Matching\RuleBasedMatchingEngine::class),
        });

        // Prises IA (config/ai.php) : gratuites aujourd'hui, remplaçables sans toucher au code.
        $this->app->bind(\App\Services\Ai\SpeechToText::class, fn () => match (config('ai.stt.provider')) {
            'hf_space' => new \App\Services\Ai\HfSpaceSpeechToText(),
            default    => new \App\Services\Ai\NullSpeechToText(),
        });
        $this->app->bind(\App\Services\Ai\DocumentOcr::class, fn () => match (config('ai.ocr.provider')) {
            'hf_space' => new \App\Services\Ai\HfSpaceDocumentOcr(),
            default    => new \App\Services\Ai\NullDocumentOcr(),
        });
        $this->app->bind(\App\Services\Ai\IntentClassifier::class, fn () => match (config('ai.llm.provider')) {
            'gemini', 'openai_compatible' => new \App\Services\Ai\LlmIntentClassifier(config('ai.llm.provider')),
            default                       => new \App\Services\Ai\NullIntentClassifier(),
        });
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping(); // ← retourne tableau direct sans "data"

        // Audit : structure active, IP et navigateur dans chaque entrée.
        Activity::saving(function (Activity $activity) {
            if ($activity->organization_id === null && ($org = ResolveActiveOrganization::current())) {
                $activity->organization_id = $org->id;
            }
            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                $request = request();
                $activity->ip_address ??= $request->ip();
                $activity->user_agent ??= mb_substr((string) $request->userAgent(), 0, 255) ?: null;
            }
        });
    }
}

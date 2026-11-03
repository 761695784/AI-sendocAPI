<?php

namespace App\Providers;

use App\Http\Middleware\ResolveActiveOrganization;
use App\Services\Access\OrganizationAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(\App\Services\Ai\TextToSpeech::class, fn () => match (config('ai.tts.provider')) {
            'hf_space' => new \App\Services\Ai\HfSpaceTextToSpeech(),
            default    => new \App\Services\Ai\NullTextToSpeech(),
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

        $this->configureRateLimits();

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

    /**
     * Limitation des requêtes sur les actions sensibles (anti brute-force, anti-spam).
     * La connexion garde en plus son verrouillage après 5 échecs (AuthController).
     * Réponse 429 en français, avec le délai d'attente.
     */
    private function configureRateLimits(): void
    {
        $tooMany = fn (string $what) => function (Request $request, array $headers) use ($what) {
            $seconds = (int) ($headers['Retry-After'] ?? 60);
            $wait    = $seconds >= 120 ? ceil($seconds / 60) . ' minutes' : $seconds . ' secondes';

            return response()->json([
                'success'     => false,
                'message'     => "Trop de tentatives ({$what}). Réessayez dans {$wait}.",
                'retry_after' => $seconds,
            ], 429, $headers);
        };
        $user = fn (Request $request) => $request->user()?->id ? 'u' . $request->user()->id : 'ip' . $request->ip();

        // Inscription : 5 comptes par IP toutes les 10 minutes.
        RateLimiter::for('register', fn (Request $r) => Limit::perMinutes(10, 5)->by('register|' . $r->ip())->response($tooMany('inscription')));

        // Mot de passe oublié / réinitialisation : 3 par email et 10 par IP, sur 10 minutes.
        RateLimiter::for('password', fn (Request $r) => [
            Limit::perMinutes(10, 3)->by('pwd|' . mb_strtolower((string) $r->input('email')) . '|' . $r->ip())->response($tooMany('mot de passe')),
            Limit::perMinutes(10, 10)->by('pwd-ip|' . $r->ip())->response($tooMany('mot de passe')),
        ]);

        // Déclarations de perte en ligne : 10 par heure et par compte.
        RateLimiter::for('declarations', fn (Request $r) => Limit::perHour(10)->by('decl|' . $user($r))->response($tooMany('déclarations')));

        // Publication de documents trouvés : 15 par heure et par compte.
        RateLimiter::for('publications', fn (Request $r) => Limit::perHour(15)->by('pub|' . $user($r))->response($tooMany('publications')));

        // Demandes de restitution directe : 10 par heure et par compte.
        RateLimiter::for('restitution-requests', fn (Request $r) => Limit::perHour(10)->by('rr|' . $user($r))->response($tooMany('demandes de restitution')));

        // Guichet : recherche de personnes et de déclarations en ligne (anti-fouille) : 60 par minute et par agent.
        RateLimiter::for('counter-search', fn (Request $r) => Limit::perMinute(60)->by('cs|' . $user($r))->response($tooMany('recherches')));
    }
}

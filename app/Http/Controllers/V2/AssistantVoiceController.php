<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiUnavailableException;
use App\Services\Assistant\VoiceLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voix de l'assistant (super admin) : écouter les phrases, faire lire celles qui manquent
 * par le modèle de synthèse (Adia_TTS pour le wolof), retirer une phrase mal prononcée.
 */
class AssistantVoiceController extends Controller
{
    public function __construct(private VoiceLibrary $voices) {}

    public function index(Request $request): JsonResponse
    {
        $lang = $this->lang($request->query('lang', 'wo'));

        return response()->json([
            'lang'         => $lang,
            'languages'    => config('assistant.languages'),
            'provider'     => config('ai.tts.provider', 'none'),
            'can_generate' => $this->voices->canGenerate($lang),
            'summary'      => $this->voices->summary($lang),
            'data'         => $this->voices->status($lang),
        ]);
    }

    /** Génère (ou régénère) l'audio d'une phrase. Long : jusqu'à 2 minutes sur un processeur gratuit. */
    public function generate(Request $request, string $lang, string $key): JsonResponse
    {
        $lang = $this->lang($lang);
        abort_unless($this->voices->canGenerate($lang), 503, $lang === 'wo'
            ? 'Synthèse vocale non configurée (AI_TTS_PROVIDER et AI_TTS_URL dans le .env).'
            : 'Le modèle de synthèse ne lit que le wolof.');

        @set_time_limit((int) config('ai.tts.hf_space.timeout', 300) + 30);

        try {
            $meta = $this->voices->generate($lang, $key);
        } catch (\InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        } catch (AiUnavailableException $e) {
            return response()->json(['message' => $e->getMessage() . ' Le service gratuit se réveille peut-être : réessayez dans une minute.'], 503);
        }

        activity()->causedBy($request->user())->withProperties(['lang' => $lang, 'key' => $key, 'model' => $meta['model']])
            ->log('Voix de l\'assistant générée');

        return response()->json($this->row($lang, $key));
    }

    public function destroy(Request $request, string $lang, string $key): JsonResponse
    {
        $lang = $this->lang($lang);
        abort_unless($this->row($lang, $key), 404);
        $this->voices->remove($lang, $key);

        activity()->causedBy($request->user())->withProperties(['lang' => $lang, 'key' => $key])->log('Voix de l\'assistant retirée');

        return response()->json($this->row($lang, $key));
    }

    private function row(string $lang, string $key): ?array
    {
        return collect($this->voices->status($lang))->firstWhere('key', $key);
    }

    private function lang(string $lang): string
    {
        abort_unless(in_array($lang, config('assistant.languages', []), true), 404, 'Langue inconnue.');

        return $lang;
    }
}

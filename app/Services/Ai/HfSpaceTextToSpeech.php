<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service Python « doctrack-tts » (Adia_TTS de CONCREE, wolof, Apache 2.0)
 * hébergé sur un Hugging Face Space : POST {url}/synthesize {text} → MP3.
 *
 * Lent sur un processeur gratuit (30 s à 2 min par phrase) : sert à préparer
 * les phrases fixes une fois pour toutes, pas à parler en direct.
 */
class HfSpaceTextToSpeech implements TextToSpeech
{
    public function available(): bool
    {
        return (bool) config('ai.tts.hf_space.url');
    }

    public function languages(): array
    {
        return config('ai.tts.languages', ['wo']);
    }

    public function synthesize(string $text, string $language): array
    {
        if (! $this->available()) {
            throw new AiUnavailableException('AI_TTS_URL manquant.');
        }
        if (! in_array($language, $this->languages(), true)) {
            throw new AiUnavailableException("La synthèse vocale ne sait pas lire la langue « {$language} ».");
        }

        try {
            $request = Http::timeout(config('ai.tts.hf_space.timeout', 300))->accept('audio/mpeg');
            if ($token = config('ai.tts.hf_space.token')) {
                $request = $request->withToken($token);
            }
            $response = $request->post(rtrim(config('ai.tts.hf_space.url'), '/') . '/synthesize', [
                'text'   => $text,
                'lang'   => $language,
                'format' => 'mp3',
            ]);
        } catch (Throwable $e) {
            Log::warning('TTS injoignable : ' . $e->getMessage());
            throw new AiUnavailableException('Service de synthèse vocale injoignable.', 0, $e);
        }

        if (! $response->successful()) {
            $detail = $response->json('detail');
            Log::warning('TTS en erreur', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            throw new AiUnavailableException(
                'Service de synthèse vocale en erreur (' . $response->status() . ')' . (is_string($detail) ? ' : ' . $detail : '.')
            );
        }

        $audio = $response->body();
        // Garde-fou : on n'enregistre que de l'audio (pas une page d'erreur HTML renvoyée par le Space en veille).
        if (strlen($audio) < 200 || ! str_starts_with((string) $response->header('Content-Type'), 'audio/')) {
            throw new AiUnavailableException('Réponse inattendue du service de synthèse vocale.');
        }

        return [
            'audio'    => $audio,
            'mime'     => 'audio/mpeg',
            'model'    => $response->header('X-Model') ?: null,
            'duration' => is_numeric($response->header('X-Duration-S')) ? (float) $response->header('X-Duration-S') : null,
            'elapsed'  => is_numeric($response->header('X-Elapsed-S')) ? (float) $response->header('X-Elapsed-S') : null,
        ];
    }
}

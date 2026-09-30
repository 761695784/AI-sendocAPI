<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service Python « doctrack-voice » (Omnilingual ASR) hébergé sur un
 * Hugging Face Space : POST {url}/transcribe (fichier + langue).
 */
class HfSpaceSpeechToText implements SpeechToText
{
    public function available(): bool
    {
        return (bool) config('ai.stt.hf_space.url');
    }

    public function transcribe(string $audioPath, string $language, ?string $filename = null): array
    {
        if (! $this->available()) {
            throw new AiUnavailableException('AI_STT_URL manquant.');
        }

        $code = config("ai.stt.languages.{$language}", config('ai.stt.languages.fr'));

        try {
            $request = Http::timeout(config('ai.stt.hf_space.timeout', 90))->acceptJson();
            if ($token = config('ai.stt.hf_space.token')) {
                $request = $request->withToken($token);
            }
            $response = $request
                ->attach('file', fopen($audioPath, 'r'), $filename ?: basename($audioPath))
                ->post(rtrim(config('ai.stt.hf_space.url'), '/') . '/transcribe', ['lang' => $code]);
        } catch (Throwable $e) {
            Log::warning('STT injoignable : ' . $e->getMessage());
            throw new AiUnavailableException('Service vocal injoignable.', 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('STT en erreur', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            throw new AiUnavailableException('Service vocal en erreur (' . $response->status() . ').');
        }

        return [
            'text'     => trim((string) $response->json('text', '')),
            'language' => $language,
            'model'    => $response->json('model'),
        ];
    }
}

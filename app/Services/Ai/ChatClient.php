<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Appel « chat/completions » d'un point d'accès compatible OpenAI (config ai.endpoints). */
class ChatClient
{
    public function configured(string $endpoint): bool
    {
        $cfg = config("ai.endpoints.{$endpoint}");

        return is_array($cfg) && ! empty($cfg['url']) && ! empty($cfg['model'])
            && ($endpoint === 'local' || ! empty($cfg['key']));
    }

    /** @return array{text: string, model: string} */
    public function complete(string $endpoint, array $messages, float $temperature = 0.2, int $maxTokens = 2000, int $timeout = 60): array
    {
        if (! $this->configured($endpoint)) {
            throw new AiUnavailableException("Point d'accès IA « {$endpoint} » non configuré.");
        }
        $cfg = config("ai.endpoints.{$endpoint}");

        try {
            $request = Http::timeout($timeout)->acceptJson();
            if (! empty($cfg['key'])) {
                $request = $request->withToken($cfg['key']);
            }
            $response = $request->post(rtrim($cfg['url'], '/') . '/chat/completions', [
                'model'       => $cfg['model'],
                'messages'    => $messages,
                'temperature' => $temperature,
                'max_tokens'  => $maxTokens,
            ]);
        } catch (Throwable $e) {
            throw new AiUnavailableException("« {$endpoint} » injoignable : " . $e->getMessage(), 0, $e);
        }

        $text = $response->json('choices.0.message.content');
        if (! $response->successful() || ! is_string($text) || trim($text) === '') {
            throw new AiUnavailableException("« {$endpoint} » en erreur ({$response->status()}) : " . mb_substr($response->body(), 0, 200));
        }

        return ['text' => $text, 'model' => (string) ($response->json('model') ?: $cfg['model'])];
    }
}

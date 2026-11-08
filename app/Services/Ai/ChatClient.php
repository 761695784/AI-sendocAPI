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
    public function complete(string $endpoint, array $messages, float $temperature = 0.2, int $maxTokens = 2000, int $timeout = 60, ?float $retryAfterMax = null): array
    {
        if (! $this->configured($endpoint)) {
            throw new AiUnavailableException("Point d'accès IA « {$endpoint} » non configuré.");
        }
        $cfg = config("ai.endpoints.{$endpoint}");

        $payload = [
            'model'       => $cfg['model'],
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ] + ($cfg['options'] ?? []); // « options » : paramètres propres au fournisseur (ex. reasoning_effort).

        for ($attempt = 1; ; $attempt++) {
            try {
                $request = Http::timeout($timeout)->acceptJson();
                if (! empty($cfg['key'])) {
                    $request = $request->withToken($cfg['key']);
                }
                $response = $request->post(rtrim($cfg['url'], '/') . '/chat/completions', $payload);
            } catch (Throwable $e) {
                throw new AiUnavailableException("« {$endpoint} » injoignable : " . $e->getMessage(), 0, $e);
            }
            // Quota par minute atteint : si le fournisseur demande d'attendre quelques secondes, on attend une fois.
            $wait = (float) ($response->header('retry-after') ?: 0);
            if ($response->status() === 429 && $attempt === 1 && $wait > 0 && $wait <= ($retryAfterMax ?? (float) config('ai.retry_after_max', 5))) {
                usleep((int) ($wait * 1_000_000));
                continue;
            }
            break;
        }

        $text = $response->json('choices.0.message.content');
        if (! $response->successful() || ! is_string($text) || trim($text) === '') {
            $reason = $response->json('choices.0.finish_reason') === 'length' ? ' — réponse coupée : augmenter max_tokens' : '';
            // Le code de l'exception porte le statut HTTP (429 = quota du fournisseur atteint).
            throw new AiUnavailableException("« {$endpoint} » en erreur ({$response->status()}){$reason} : " . mb_substr($response->body(), 0, 400), $response->status());
        }

        return ['text' => $text, 'model' => (string) ($response->json('model') ?: $cfg['model'])];
    }
}

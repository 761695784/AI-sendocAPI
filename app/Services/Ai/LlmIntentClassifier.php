<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Classement d'une phrase libre par un modèle de langage (Gemini, ou toute
 * API compatible OpenAI : Groq, OpenRouter, Ollama…).
 *
 * Le modèle ne DÉCIDE rien : il propose une intention parmi une liste
 * fermée ; l'assistant guidé reprend ensuite la main (questions,
 * récapitulatif, confirmation). Les chiffres sont masqués avant l'envoi.
 */
class LlmIntentClassifier implements IntentClassifier
{
    public function __construct(private string $provider) {}

    public function available(): bool
    {
        return match ($this->provider) {
            'gemini'            => (bool) config('ai.llm.gemini.key'),
            'openai_compatible' => (bool) (config('ai.llm.openai_compatible.url') && config('ai.llm.openai_compatible.model')),
            default             => false,
        };
    }

    public function classify(string $text, array $intents, array $documentTypes): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $prompt = $this->prompt(self::mask($text), $intents, $documentTypes);

        try {
            $raw = $this->provider === 'gemini' ? $this->gemini($prompt) : $this->openAi($prompt);
        } catch (Throwable $e) {
            Log::warning('Classement LLM impossible : ' . $e->getMessage());

            return null;
        }

        $json = json_decode(self::extractJson((string) $raw), true);
        if (! is_array($json)) {
            return null;
        }

        $intent = in_array($json['intent'] ?? null, $intents, true) ? $json['intent'] : null;
        $type   = in_array($json['document_type'] ?? null, $documentTypes, true) ? $json['document_type'] : null;

        return $intent || $type ? ['intent' => $intent, 'document_type' => $type] : null;
    }

    /** Masque les chiffres (numéros de pièce, téléphones). */
    public static function mask(string $text): string
    {
        return preg_replace('/\d/', '#', $text);
    }

    private function prompt(string $text, array $intents, array $documentTypes): string
    {
        return "Tu aides DocTrack, un service sénégalais de déclaration de pièces perdues et de restitution de documents trouvés.\n"
            . "Le message de l'utilisateur peut être en français, en wolof ou un mélange des deux (souvent transcrit à partir de la voix, avec des fautes).\n"
            . 'Intentions possibles : ' . implode(', ', $intents) . ", ou null si aucune ne convient.\n"
            . '- declare_loss : la personne a perdu une pièce ; report_found : elle a trouvé le document de quelqu\'un ; track_status : elle suit son dossier ; '
            . 'where_to_go : où récupérer sa pièce ; what_to_bring : documents à apporter ; proxy : faire récupérer par un proche ; human : parler à un agent ; help : menu.' . "\n"
            . 'Types de pièce possibles : ' . implode(' | ', $documentTypes) . ", ou null.\n"
            . 'Réponds UNIQUEMENT en JSON : {"intent": "...", "document_type": "..."}' . "\n"
            . 'Message : "' . str_replace('"', "'", mb_substr($text, 0, 500)) . '"';
    }

    private function gemini(string $prompt): ?string
    {
        $cfg = config('ai.llm.gemini');
        $response = Http::timeout(config('ai.llm.timeout', 15))
            ->withHeaders(['x-goog-api-key' => $cfg['key']])
            ->post(rtrim($cfg['url'], '/') . '/' . $cfg['model'] . ':generateContent', [
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
            ])->throw();

        return $response->json('candidates.0.content.parts.0.text');
    }

    private function openAi(string $prompt): ?string
    {
        $cfg = config('ai.llm.openai_compatible');
        $request = Http::timeout(config('ai.llm.timeout', 15));
        if (! empty($cfg['key'])) {
            $request = $request->withToken($cfg['key']);
        }
        $response = $request->post(rtrim($cfg['url'], '/') . '/chat/completions', [
            'model'       => $cfg['model'],
            'temperature' => 0,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
        ])->throw();

        return $response->json('choices.0.message.content');
    }

    private static function extractJson(string $raw): string
    {
        return preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : $raw;
    }
}

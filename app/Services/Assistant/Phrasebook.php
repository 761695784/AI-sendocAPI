<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Storage;

/**
 * Phrases de l'assistant (config/assistant.php) dans la langue de la
 * conversation, avec l'audio pré-généré quand il existe.
 */
class Phrasebook
{
    public function text(string $key, string $lang, array $params = []): string
    {
        $phrase = config("assistant.phrases.{$key}");
        $template = is_array($phrase) ? ($phrase[$lang] ?? $phrase['fr'] ?? $key) : $key;

        uksort($params, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($params as $name => $value) {
            $template = str_replace(':' . $name, (string) $value, $template);
        }

        return $template;
    }

    /** @return array{key: string, text: string, audio_url: ?string} */
    public function say(string $key, string $lang, array $params = []): array
    {
        return [
            'key'       => $key,
            'text'      => $this->text($key, $lang, $params),
            'audio_url' => $params === [] ? $this->audioUrl($key, $lang) : null,
        ];
    }

    /** Message libre (ex. liste de dossiers) sans audio. */
    public function raw(string $text, string $key = 'raw'): array
    {
        return ['key' => $key, 'text' => $text, 'audio_url' => null];
    }

    public function button(string $key, string $lang, ?string $value = null): array
    {
        $labels = config("assistant.buttons.{$key}", []);

        return ['label' => $labels[$lang] ?? $labels['fr'] ?? $key, 'value' => $value ?? ($labels['fr'] ?? $key)];
    }

    public function audioPath(string $key, string $lang): string
    {
        return trim(config('assistant.audio_dir', 'assistant/audio'), '/') . "/{$lang}/{$key}.mp3";
    }

    public function audioUrl(string $key, string $lang): ?string
    {
        if (! Storage::disk('local')->exists($this->audioPath($key, $lang))) {
            return null;
        }

        return url("/api/v2/assistant/audio/{$lang}/{$key}");
    }

    /**
     * Phrases pouvant être enregistrées à l'avance (sans paramètre).
     *
     * @return array<string, string>  clé => texte
     */
    public function staticPhrases(string $lang): array
    {
        return collect(config('assistant.phrases', []))
            ->map(fn ($p) => $p[$lang] ?? null)
            ->filter(fn ($t) => $t !== null && ! preg_match('/:[a-z_]+/', $t))
            ->all();
    }
}

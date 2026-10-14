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

    /**
     * Adresse de l'audio d'une phrase, ou null s'il n'existe pas… ou s'il ne dit plus la même chose :
     * quand le texte a été corrigé depuis la génération, on préfère le texte seul à une voix qui dit autre chose.
     */
    public function audioUrl(string $key, string $lang): ?string
    {
        if (! Storage::disk('local')->exists($this->audioPath($key, $lang))) {
            return null;
        }

        $meta = $this->audioManifest($lang)[$key] ?? null;
        if ($meta === null) {
            // Fichier déposé à la main (ancienne méthode Colab), sans fiche : on le sert tel quel.
            return url("/api/v2/assistant/audio/{$lang}/{$key}");
        }
        if (($meta['hash'] ?? null) !== self::hash($this->text($key, $lang))) {
            return null;
        }

        // ?v= : le navigateur recharge l'audio quand il a été régénéré.
        return url("/api/v2/assistant/audio/{$lang}/{$key}") . '?v=' . substr($meta['hash'], 0, 8);
    }

    /** Empreinte d'un texte : sert à savoir si un audio correspond encore à sa phrase. */
    public static function hash(string $text): string
    {
        return sha1(preg_replace('/\s+/u', ' ', trim($text)));
    }

    public function manifestPath(string $lang): string
    {
        return trim(config('assistant.audio_dir', 'assistant/audio'), '/') . "/{$lang}/manifest.json";
    }

    /**
     * Fiches des audios générés : clé => {hash, text, model, generated_at, duration, bytes}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function audioManifest(string $lang): array
    {
        // Relu à chaque fois (petit fichier) : jamais de fiche périmée gardée en mémoire par un serveur qui reste allumé.
        $raw = Storage::disk('local')->get($this->manifestPath($lang));

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    public function saveAudioManifest(string $lang, array $manifest): void
    {
        ksort($manifest);
        Storage::disk('local')->put($this->manifestPath($lang), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

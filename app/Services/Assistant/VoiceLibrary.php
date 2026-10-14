<?php

namespace App\Services\Assistant;

use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\TextToSpeech;
use Illuminate\Support\Facades\Storage;

/**
 * Voix de l'assistant : les phrases fixes (sans nom, sans numéro) sont lues UNE fois
 * par le modèle de synthèse (Adia_TTS pour le wolof), enregistrées en MP3, puis servies
 * comme de simples fichiers : la réponse parlée est alors instantanée et gratuite.
 *
 * Chaque audio a une fiche (empreinte du texte, modèle, date). Si le texte d'une phrase
 * est corrigé, son audio devient « à refaire » et n'est plus proposé tant qu'il n'est pas régénéré.
 *
 * Les phrases à paramètres (nom, référence de dossier…) ne sont jamais envoyées au service :
 * aucune donnée personnelle ne quitte DocTrack pour la synthèse vocale.
 */
class VoiceLibrary
{
    public const READY   = 'ready';     // audio à jour
    public const STALE   = 'stale';     // le texte a changé depuis la génération
    public const MISSING = 'missing';   // pas encore d'audio
    public const MANUAL  = 'manual';    // fichier déposé à la main, sans fiche

    public function __construct(private Phrasebook $phrases, private TextToSpeech $tts) {}

    public function canGenerate(string $lang): bool
    {
        return $this->tts->available() && in_array($lang, $this->tts->languages(), true);
    }

    /**
     * État de chaque phrase fixe d'une langue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(string $lang): array
    {
        $manifest = $this->phrases->audioManifest($lang);
        $disk     = Storage::disk('local');

        return collect($this->phrases->staticPhrases($lang))->map(function (string $text, string $key) use ($lang, $manifest, $disk) {
            $exists = $disk->exists($this->phrases->audioPath($key, $lang));
            $meta   = $manifest[$key] ?? null;
            $state  = match (true) {
                ! $exists                                         => self::MISSING,
                $meta === null                                    => self::MANUAL,
                ($meta['hash'] ?? null) !== Phrasebook::hash($text) => self::STALE,
                default                                           => self::READY,
            };

            return [
                'key'          => $key,
                'text'         => $text,
                'state'        => $state,
                // Écoute par l'administrateur, même si l'audio est « à refaire ».
                'audio_url'    => $exists ? url("/api/v2/assistant/audio/{$lang}/{$key}") . '?v=' . substr($meta['hash'] ?? (string) $disk->lastModified($this->phrases->audioPath($key, $lang)), 0, 8) : null,
                'spoken_text'  => $state === self::STALE ? ($meta['text'] ?? null) : null,
                'model'        => $meta['model'] ?? null,
                'generated_at' => $meta['generated_at'] ?? null,
                'duration'     => $meta['duration'] ?? null,
                'bytes'        => $exists ? $disk->size($this->phrases->audioPath($key, $lang)) : null,
            ];
        })->values()->all();
    }

    /** @return array{total: int, ready: int, stale: int, missing: int, manual: int} */
    public function summary(string $lang): array
    {
        $states = array_count_values(array_column($this->status($lang), 'state'));

        return [
            'total'   => array_sum($states),
            'ready'   => $states[self::READY] ?? 0,
            'stale'   => $states[self::STALE] ?? 0,
            'missing' => $states[self::MISSING] ?? 0,
            'manual'  => $states[self::MANUAL] ?? 0,
        ];
    }

    /**
     * Fait lire une phrase par le service de synthèse et enregistre le MP3.
     *
     * @return array<string, mixed>  la fiche enregistrée
     *
     * @throws AiUnavailableException
     */
    public function generate(string $lang, string $key): array
    {
        $text = $this->phrases->staticPhrases($lang)[$key] ?? null;
        if ($text === null) {
            throw new \InvalidArgumentException("Phrase « {$key} » inconnue ou à paramètres : pas d'audio fixe.");
        }
        if (mb_strlen($text) > (int) config('ai.tts.max_chars', 600)) {
            throw new \InvalidArgumentException("Phrase « {$key} » trop longue pour la synthèse vocale.");
        }

        $result = $this->tts->synthesize($text, $lang);

        Storage::disk('local')->put($this->phrases->audioPath($key, $lang), $result['audio']);

        $manifest       = $this->phrases->audioManifest($lang);
        $manifest[$key] = [
            'hash'         => Phrasebook::hash($text),
            'text'         => $text,
            'model'        => $result['model'] ?? config('ai.tts.provider'),
            'generated_at' => now()->toIso8601String(),
            'duration'     => $result['duration'],
            'elapsed'      => $result['elapsed'],
            'bytes'        => strlen($result['audio']),
        ];
        $this->phrases->saveAudioManifest($lang, $manifest);

        return $manifest[$key];
    }

    /** Retire un audio (phrase mal prononcée) : l'assistant n'affichera plus que le texte. */
    public function remove(string $lang, string $key): bool
    {
        $path    = $this->phrases->audioPath($key, $lang);
        $existed = Storage::disk('local')->exists($path);
        Storage::disk('local')->delete($path);

        $manifest = $this->phrases->audioManifest($lang);
        if (isset($manifest[$key])) {
            unset($manifest[$key]);
            $this->phrases->saveAudioManifest($lang, $manifest);
        }

        return $existed;
    }

    /**
     * Fichiers déposés à la main (ZIP produit sur Colab) : crée leur fiche avec le texte actuel,
     * pour qu'une correction ultérieure du texte les signale « à refaire ».
     */
    public function adopt(string $lang, string $model = 'importé'): int
    {
        $manifest = $this->phrases->audioManifest($lang);
        $count    = 0;
        foreach ($this->phrases->staticPhrases($lang) as $key => $text) {
            $path = $this->phrases->audioPath($key, $lang);
            if (isset($manifest[$key]) || ! Storage::disk('local')->exists($path)) {
                continue;
            }
            $manifest[$key] = [
                'hash'         => Phrasebook::hash($text),
                'text'         => $text,
                'model'        => $model,
                'generated_at' => now()->toIso8601String(),
                'duration'     => null,
                'elapsed'      => null,
                'bytes'        => Storage::disk('local')->size($path),
            ];
            $count++;
        }
        if ($count) {
            $this->phrases->saveAudioManifest($lang, $manifest);
        }

        return $count;
    }
}

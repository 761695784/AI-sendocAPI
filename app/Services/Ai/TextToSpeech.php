<?php

namespace App\Services\Ai;

/** Texte → voix (pour préparer les phrases de l'assistant). */
interface TextToSpeech
{
    public function available(): bool;

    /** Langues que ce fournisseur sait lire (ex. ['wo']). */
    public function languages(): array;

    /**
     * @param  string  $language  'wo'
     * @return array{audio: string, mime: string, model: ?string, duration: ?float, elapsed: ?float}  audio = contenu binaire (MP3)
     *
     * @throws AiUnavailableException
     */
    public function synthesize(string $text, string $language): array;
}

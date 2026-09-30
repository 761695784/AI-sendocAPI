<?php

namespace App\Services\Ai;

/** Voix → texte. */
interface SpeechToText
{
    public function available(): bool;

    /**
     * @param  string  $language  'fr' | 'wo'
     * @return array{text: string, language: string, model: ?string}
     *
     * @throws AiUnavailableException
     */
    public function transcribe(string $audioPath, string $language, ?string $filename = null): array;
}

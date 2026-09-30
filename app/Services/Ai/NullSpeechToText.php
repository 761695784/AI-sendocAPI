<?php

namespace App\Services\Ai;

class NullSpeechToText implements SpeechToText
{
    public function available(): bool
    {
        return false;
    }

    public function transcribe(string $audioPath, string $language, ?string $filename = null): array
    {
        throw new AiUnavailableException('Aucun service de reconnaissance vocale configuré (AI_STT_PROVIDER).');
    }
}

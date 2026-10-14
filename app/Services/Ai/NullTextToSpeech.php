<?php

namespace App\Services\Ai;

class NullTextToSpeech implements TextToSpeech
{
    public function available(): bool
    {
        return false;
    }

    public function languages(): array
    {
        return [];
    }

    public function synthesize(string $text, string $language): array
    {
        throw new AiUnavailableException('Aucun service de synthèse vocale configuré (AI_TTS_PROVIDER).');
    }
}

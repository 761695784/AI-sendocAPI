<?php

namespace App\Services\Ai;

class NullDocumentOcr implements DocumentOcr
{
    public function available(): bool
    {
        return false;
    }

    public function read(string $imagePath): array
    {
        throw new AiUnavailableException('Aucun service OCR configuré (AI_OCR_PROVIDER).');
    }
}

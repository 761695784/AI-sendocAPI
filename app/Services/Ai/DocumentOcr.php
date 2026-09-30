<?php

namespace App\Services\Ai;

/** Lecture du texte d'une photo (OCR). */
interface DocumentOcr
{
    public function available(): bool;

    /**
     * @return array{engine: ?string, rotation: int, width: int, height: int,
     *               lines: array<int, array{text: string, confidence: float, box: array<int, array{0: float, 1: float}>}>}
     *
     * @throws AiUnavailableException
     */
    public function read(string $imagePath): array;
}

<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/** Minuscules, sans accents ni ponctuation, espaces simples (ñ → n, ë → e…). */
class TextNormalizer
{
    public static function normalize(?string $text): string
    {
        $text = Str::lower(Str::ascii((string) $text));
        $text = preg_replace("/['’`´]/u", ' ', $text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

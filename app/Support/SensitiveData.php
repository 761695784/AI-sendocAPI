<?php

namespace App\Support;

use App\Services\SmsService;
use Illuminate\Support\Str;

/**
 * Données sensibles (numéro de pièce, téléphone) :
 * - chiffrées (Crypt) pour être réaffichées à un agent habilité ;
 * - hachées (HMAC-SHA256, clé dédiée) pour la recherche exacte et le matching,
 *   sans jamais déchiffrer.
 */
class SensitiveData
{
    public static function hash(?string $normalized): ?string
    {
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, self::key());
    }

    /** "1 234-567.890 123" → "1234567890123" ; "a12 345" → "A12345" */
    public static function normalizeDocNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($number)));

        return $normalized === '' ? null : $normalized;
    }

    public static function hashDocNumber(?string $number): ?string
    {
        return self::hash(self::normalizeDocNumber($number));
    }

    /** Téléphone sénégalais normalisé (+221…) puis haché ; sinon chiffres bruts. */
    public static function hashPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $normalized = SmsService::normalizeSenegalPhone($phone) ?? preg_replace('/\D+/', '', $phone);

        return self::hash($normalized);
    }

    public static function last4(?string $value): ?string
    {
        $normalized = self::normalizeDocNumber($value);

        return $normalized ? substr($normalized, -4) : null;
    }

    private static function key(): string
    {
        $key = config('doctrack.hash_key') ?: config('app.key');

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}

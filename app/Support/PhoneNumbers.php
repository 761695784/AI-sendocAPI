<?php

namespace App\Support;

use App\Models\User;
use App\Services\SmsService;

/**
 * Un numéro de téléphone = un seul compte DocTrack.
 * Comparaison sur le numéro normalisé (« 77 123 45 67 » = « +221771234567 »).
 */
class PhoneNumbers
{
    public static function normalize(?string $phone): string
    {
        $phone = (string) $phone;

        return SmsService::normalizeSenegalPhone($phone) ?? preg_replace('/\D+/', '', $phone);
    }

    /** Un autre compte (non supprimé) utilise-t-il déjà ce numéro ? */
    public static function takenByAnotherUser(?string $phone, ?int $exceptUserId = null): bool
    {
        $normalized = self::normalize($phone);
        $digits     = preg_replace('/\D+/', '', $normalized);
        if (strlen($digits) < 6) {
            return false;
        }

        return User::query()
            ->where('Phone', 'like', '%' . substr($digits, -4))
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->limit(200)
            ->get(['id', 'Phone'])
            ->contains(fn (User $u) => self::normalize($u->Phone) === $normalized);
    }

    /** Règle de validation « numéro libre » pour Validator::make. */
    public static function uniqueRule(?int $exceptUserId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($exceptUserId) {
            if (is_string($value) && self::takenByAnotherUser($value, $exceptUserId)) {
                $fail('Ce numéro de téléphone est déjà utilisé par un autre compte.');
            }
        };
    }
}

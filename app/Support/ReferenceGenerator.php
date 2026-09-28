<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferenceGenerator
{
    /**
     * Prochaine valeur d'un compteur, sans doublon possible (verrou de ligne).
     */
    public static function next(string $name): int
    {
        return DB::transaction(function () use ($name) {
            $row = DB::table('reference_sequences')->where('name', $name)->lockForUpdate()->first();

            if (! $row) {
                DB::table('reference_sequences')->insertOrIgnore([
                    'name' => $name, 'value' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('reference_sequences')->where('name', $name)->lockForUpdate()->first();
            }

            $value = $row->value + 1;
            DB::table('reference_sequences')->where('name', $name)->update(['value' => $value, 'updated_at' => now()]);

            return $value;
        });
    }

    /** DT-2026-000123 */
    public static function declaration(): string
    {
        $year = now()->year;

        return sprintf('DT-%d-%06d', $year, self::next("declaration:{$year}"));
    }

    /** TR-2026-000123 (document trouvé) */
    public static function foundDocument(): string
    {
        $year = now()->year;

        return sprintf('TR-%d-%06d', $year, self::next("found:{$year}"));
    }

    /** CP-2026-000045 (certificat officiel) */
    public static function officialCertificate(): string
    {
        $year = now()->year;

        return sprintf('CP-%d-%06d', $year, self::next("certificate:{$year}"));
    }

    /** RS-2026-000012 (décharge de restitution officielle) */
    public static function restitution(): string
    {
        $year = now()->year;

        return sprintf('RS-%d-%06d', $year, self::next("restitution:{$year}"));
    }

    /** Code de vérification imprimé sur le certificat (sans caractères ambigus). */
    public static function verificationCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (
            // Un code unique pour tous les documents vérifiables (certificats et décharges).
            DB::table('certificat_de_pertes')->where('verification_code', $code)->exists()
            || (\Illuminate\Support\Facades\Schema::hasTable('restitutions') && DB::table('restitutions')->where('verification_code', $code)->exists())
        );

        return $code;
    }

    public static function formatVerificationCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}

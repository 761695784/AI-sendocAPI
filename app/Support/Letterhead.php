<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * En-tête des documents officiels au format administratif sénégalais :
 *
 *   RÉPUBLIQUE DU SÉNÉGAL                                   [drapeau]
 *   Un Peuple – Un But – Une Foi
 *   ──────
 *   MINISTÈRE DE …                                   Dakar, le 13 octobre 2026
 *   DIRECTION / HAUT-COMMANDEMENT …
 *   (échelon intermédiaire)
 *   STRUCTURE
 *   N° …
 *
 * Les intitulés viennent de config('doctrack.letterhead') selon l'institution de rattachement.
 */
class Letterhead
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * @return array{lines: string[], city: string, signatory: string, official: bool}
     */
    public static function for(?Organization $structure): array
    {
        if (! $structure) {
            return [
                'lines'     => [config('doctrack.letterhead.platform')],
                'city'      => config('doctrack.letterhead.default_city', 'Dakar'),
                'signatory' => 'Pour la plateforme DocTrack',
                'official'  => false,
            ];
        }

        $structure->loadMissing(['type', 'territory.parent']);
        $institution = $structure->institution();
        $conf        = config('doctrack.letterhead.institutions.' . $institution->code, []);

        // Hiérarchie : lignes de l'institution, puis les échelons intermédiaires et la structure elle-même.
        $lines = $conf['lines'] ?? [$institution->name];
        $chain = Organization::withTrashed()->whereIn('id', $structure->ancestorIds())->orderBy('depth')->get()
            ->reject(fn (Organization $o) => $o->id === $institution->id)
            ->pluck('name')->all();
        if ($structure->id !== $institution->id) {
            $chain[] = $structure->name;
        }

        $typeCode  = $structure->type?->code;
        $signatory = $conf['signatory'][$typeCode] ?? $conf['signatory']['default'] ?? 'Le Chef de service';

        return [
            'lines'     => array_values(array_unique(array_merge($lines, $chain))),
            'city'      => self::city($structure),
            'signatory' => $signatory,
            'official'  => (bool) $conf,
        ];
    }

    /** Ville de la structure : son département, sinon sa région, sinon la ville par défaut. */
    public static function city(?Organization $structure): string
    {
        $t = $structure?->territory;

        return $t?->name ?? config('doctrack.letterhead.default_city', 'Dakar');
    }

    /** « 13 octobre 2026 » */
    public static function longDate(?\DateTimeInterface $date = null): string
    {
        $d = Carbon::instance($date ?? now())->timezone('Africa/Dakar');

        return $d->day . ($d->day === 1 ? 'er' : '') . ' ' . self::MONTHS[$d->month - 1] . ' ' . $d->year;
    }
}

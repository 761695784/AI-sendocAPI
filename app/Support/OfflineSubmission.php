<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Envois préparés hors connexion par l'application (déclaration, publication).
 *
 * L'appareil attribue à chaque envoi un identifiant unique (client_uuid) et note
 * l'heure de saisie (client_created_at). Si le même envoi arrive deux fois (réseau
 * coupé au mauvais moment, nouvelle tentative), le serveur renvoie ce qu'il a déjà
 * créé au lieu de créer un doublon.
 */
class OfflineSubmission
{
    /** Règles de validation communes. */
    public static function rules(): array
    {
        return [
            'client_uuid'       => 'nullable|uuid',
            'client_created_at' => 'nullable|date',
        ];
    }

    /**
     * Heure de saisie sur l'appareil : jamais dans le futur, ni plus ancienne que
     * 90 jours (horloge du téléphone déréglée) ; sinon ignorée.
     */
    public static function createdAt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            $at = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
        if ($at->lt(now()->subDays(90))) {
            return null;
        }

        return $at->gt(now()) ? now() : $at;
    }

    /** Colonnes à enregistrer avec la déclaration ou le document. */
    public static function columns(array $data): array
    {
        return [
            'client_uuid'       => $data['client_uuid'] ?? null,
            'client_created_at' => self::createdAt($data['client_created_at'] ?? null),
        ];
    }
}

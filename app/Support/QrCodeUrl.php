<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class QrCodeUrl
{
    /**
     * Lien encodé dans le QR code (page du front où le trouveur laisse son numéro).
     */
    public static function forToken(string $token): string
    {
        return rtrim(config('doctrack.frontend_url'), '/') . '/found-qr?token=' . urlencode($token);
    }

    /**
     * URL signée et temporaire de l'image PNG du QR code d'un utilisateur.
     */
    public static function imageFor(User $user, int $minutes = 60): ?string
    {
        if (empty($user->qr_code_token)) {
            return null;
        }

        return URL::temporarySignedRoute('files.qr', now()->addMinutes($minutes), ['uuid' => $user->uuid]);
    }
}

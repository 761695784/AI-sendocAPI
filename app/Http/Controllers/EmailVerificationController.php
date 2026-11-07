<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;

class EmailVerificationController extends Controller
{
 public function verify(Request $request)
    {
        // ✅ Validation des champs
        $request->validate([
            'email' => 'required|email',
            'code' => 'required'
        ]);

        // ✅ Récupérer l'utilisateur par email
        $user = User::where('email', $request->email)->first();

        // Code à 6 chiffres : 5 essais maximum par compte, puis il est annulé ; valable 48 h.
        // Pour obtenir un nouveau lien d'activation : « Mot de passe oublié ».
        $expired = $user && $user->created_at && $user->created_at->lt(now()->subHours(48));
        if ($user && ! empty($user->email_verification_code) && $expired) {
            $user->forceFill(['email_verification_code' => null])->save();
        }

        // ✅ Vérifier le code
        if (! $user
            || empty($user->email_verification_code)
            || ! hash_equals((string) $user->email_verification_code, (string) $request->code)) {
            if ($user && ! empty($user->email_verification_code)) {
                $key = 'email-code:' . $user->id;
                RateLimiter::hit($key, 86400);
                if (RateLimiter::attempts($key) >= 5) {
                    $user->forceFill(['email_verification_code' => null])->save();
                    RateLimiter::clear($key);
                }
            }

            return response()->json([
                'message' => 'Code incorrect ou expiré. Si le problème persiste, utilisez « Mot de passe oublié » pour recevoir un lien d\'activation.',
            ], 401);
        }

        RateLimiter::clear('email-code:' . $user->id);

        // ✅ Mettre à jour la vérification de l'email
        $user->email_verified_at = now();
        $user->email_verification_code = null;
        $user->save();

        // Déclarations faites au guichet avant l'inscription : rattachées au nouveau compte.
        app(\App\Services\Citizens\CitizenAccountService::class)->attachOrphanProfiles($user);

        // ✅ Générer le token JWT
        $token = JWTAuth::fromUser($user);

        // ✅ Retourner la réponse
        return response()->json([
            'message' => 'Email vérifié avec succès',
            'access_token' => $token,
            'user' => $user
        ]);
    }

}

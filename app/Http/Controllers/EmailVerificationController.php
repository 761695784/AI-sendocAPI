<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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

        // ✅ Vérifier le code
        if (! $user
            || empty($user->email_verification_code)
            || ! hash_equals((string) $user->email_verification_code, (string) $request->code)) {
            return response()->json(['message' => 'Code incorrect'], 401);
        }

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

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
        if (!$user || $user->email_verification_code !== $request->code) {
            return response()->json(['message' => 'Code incorrect'], 401);
        }

        // ✅ Mettre à jour la vérification de l'email
        $user->email_verified_at = now();
        $user->email_verification_code = null;
        $user->save();

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

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Un compte suspendu (agent désaffecté, compte bloqué) perd l'accès
 * immédiatement, même avec un jeton JWT encore valide.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte est suspendu. Contactez votre responsable.',
            ], 403);
        }

        // Email jamais vérifié (même règle qu'à la connexion) : un jeton obtenu autrement ne sert à rien.
        if ($user && is_null($user->email_verified_at) && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Email non vérifié.',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Access\OrganizationAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Détermine la structure au nom de laquelle l'agent agit.
 *
 * - En-tête "X-Organization: <uuid>" si l'agent en a plusieurs ;
 * - sinon son affectation principale.
 *
 * L'organisation est rangée dans les attributs de la requête (sûr sous Octane)
 * et enregistrée dans chaque entrée du journal d'audit.
 */
class ResolveActiveOrganization
{
    public const ATTRIBUTE = 'doctrack.active_organization';

    public static function current(): ?\App\Models\Organization
    {
        return app()->bound('request') ? request()->attributes->get(self::ATTRIBUTE) : null;
    }

    public function __construct(private OrganizationAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $requested = $request->header('X-Organization');
            $org       = $this->access->resolveActive($user, $requested);

            if ($requested && ! $org) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'êtes pas rattaché à cette organisation.',
                ], 403);
            }

            if ($org) {
                $request->attributes->set(self::ATTRIBUTE, $org);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private OrganizationAccess $access) {}

    /**
     * Affectations actives de l'utilisateur connecté et droits associés.
     */
    public function organizations(Request $request)
    {
        $user = $request->user();

        $data = $this->access->memberships($user)->map(function ($m) {
            $m->organization->loadMissing('type');

            return [
                'membership_uuid' => $m->uuid,
                'role'            => $m->role,
                'is_primary'      => $m->is_primary,
                'matricule'       => $m->matricule,
                'abilities'       => config("doctrack_access.abilities.{$m->role}", []),
                'organization'    => [
                    'uuid'  => $m->organization->uuid,
                    'name'  => $m->organization->name,
                    'type'  => $m->organization->type->code,
                    'level' => $m->organization->type->level,
                ],
            ];
        })->values();

        return response()->json([
            'account_type'        => $user->account_type,
            'is_super_admin'      => $user->isSuperAdmin(),
            'active_organization' => optional(\App\Http\Middleware\ResolveActiveOrganization::current())->uuid,
            'memberships'         => $data,
        ]);
    }
}

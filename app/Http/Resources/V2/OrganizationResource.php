<?php

namespace App\Http\Resources\V2;

use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $access = app(OrganizationAccess::class);

        return [
            'uuid'      => $this->uuid,
            'name'      => $this->name,
            'code'      => $this->code,
            'type'      => $this->whenLoaded('type', fn () => [
                'code'  => $this->type->code,
                'name'  => $this->type->name,
                'level' => $this->type->level,
            ]),
            'parent'    => $this->whenLoaded('parent', fn () => $this->parent ? [
                'uuid' => $this->parent->uuid,
                'name' => $this->parent->name,
            ] : null),
            'territory' => $this->whenLoaded('territory', fn () => $this->territory ? [
                'code'  => $this->territory->code,
                'name'  => $this->territory->name,
                'level' => $this->territory->level,
            ] : null),
            'address'   => $this->address,
            'phone'     => $this->phone,
            'email'     => $this->email,
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'accepts_collections' => $this->accepts_collections,
            'depth'     => $this->depth,
            'is_active' => $this->is_active,
            'children_count' => $this->whenCounted('children'),
            'members_count'  => $this->whenCounted('activeMemberships'),

            // Ce que l'utilisateur connecté peut faire ici (pour adapter l'interface).
            'my_role'   => $viewer ? ($viewer->isSuperAdmin() ? 'super_admin' : $access->effectiveRole($viewer, $this->resource)) : null,
            'can'       => $viewer ? [
                'update'        => $access->can($viewer, 'organizations.update', $this->resource),
                'create_child'  => $access->can($viewer, 'organizations.create_child', $this->resource),
                'manage_members'=> $access->can($viewer, 'members.manage', $this->resource),
            ] : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

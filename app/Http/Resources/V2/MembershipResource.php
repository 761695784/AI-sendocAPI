<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'       => $this->uuid,
            'role'       => $this->role,
            'matricule'  => $this->matricule,
            'position'   => $this->position,
            'is_primary' => $this->is_primary,
            'is_active'  => $this->isActive(),
            'starts_at'  => $this->starts_at,
            'ends_at'    => $this->ends_at,
            'user'       => $this->whenLoaded('user', fn () => [
                'uuid'          => $this->user->uuid,
                'FirstName'     => $this->user->FirstName,
                'LastName'      => $this->user->LastName,
                'email'         => $this->user->email,
                'Phone'         => $this->user->Phone,
                'status'        => $this->user->status,
                'last_login_at' => $this->user->last_login_at,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => [
                'uuid' => $this->organization->uuid,
                'name' => $this->organization->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}

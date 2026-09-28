<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue minimale d'un utilisateur pour un tiers (public ou autre citoyen).
 * Jamais d'email, de téléphone, d'adresse ni de jeton.
 */
class PublicUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'      => $this->uuid,
            'FirstName' => $this->FirstName,
            'LastName'  => $this->LastName ? mb_substr($this->LastName, 0, 1) . '.' : null,
        ];
    }
}

<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitizenProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'         => $this->uuid,
            'first_name'   => $this->first_name,
            'last_name'    => $this->last_name,
            'birth_date'   => $this->birth_date?->toDateString(),
            'phone_masked' => $this->maskedPhone(),
            'has_account'  => $this->user_id !== null,
        ];
    }
}

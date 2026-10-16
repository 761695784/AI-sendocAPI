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
            // none : pas de compte ; pending : compte créé, mot de passe pas encore choisi ; active.
            'account_status' => $this->user_id === null ? 'none' : ($this->user?->email_verified_at ? 'active' : 'pending'),
            'email_masked'   => $this->user?->email ? self::maskEmail($this->user->email) : null,
        ];
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2) . str_repeat('•', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}

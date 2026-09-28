<?php

namespace App\Http\Requests\V2;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role'       => ['sometimes', Rule::in(OrganizationMembership::ROLES)],
            'matricule'  => 'sometimes|nullable|string|max:50',
            'position'   => 'sometimes|nullable|string|max:255',
            'is_primary' => 'sometimes|boolean',
        ];
    }
}

<?php

namespace App\Http\Requests\V2;

use App\Models\OrganizationMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'      => 'required|email|max:100',
            'FirstName'  => 'required|string|max:40',
            'LastName'   => 'required|string|max:40',
            'Phone'      => 'required|string|max:20',
            'role'       => ['required', Rule::in(OrganizationMembership::ROLES)],
            'matricule'  => 'nullable|string|max:50',
            'position'   => 'nullable|string|max:255',
            'is_primary' => 'sometimes|boolean',
        ];
    }
}

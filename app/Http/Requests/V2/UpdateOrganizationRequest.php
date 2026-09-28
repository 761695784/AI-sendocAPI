<?php

namespace App\Http\Requests\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => 'sometimes|required|string|max:255',
            'code'           => ['sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('organizations', 'code')->ignore($this->route('organization'))],
            'territory_code' => 'sometimes|nullable|string|exists:territories,code',
            'address'        => 'sometimes|nullable|string|max:255',
            'phone'          => 'sometimes|nullable|string|max:30',
            'email'          => 'sometimes|nullable|email|max:255',
            'latitude'            => 'sometimes|nullable|numeric|between:12,17|required_with:longitude',
            'longitude'           => 'sometimes|nullable|numeric|between:-18,-11|required_with:latitude',
            'accepts_collections' => 'sometimes|boolean',
            // Rattachement à un autre parent : super_admin uniquement (contrôlé dans le contrôleur).
            'parent_uuid'    => 'sometimes|nullable|uuid|exists:organizations,uuid',
        ];
    }
}

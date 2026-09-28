<?php

namespace App\Http\Requests\V2;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorisation fine dans le contrôleur (dépend du parent)
    }

    public function rules(): array
    {
        return [
            'organization_type' => 'required|string|exists:organization_types,code',
            'parent_uuid'       => 'nullable|uuid|exists:organizations,uuid',
            'name'              => 'required|string|max:255',
            'code'              => 'nullable|string|max:50|unique:organizations,code',
            'territory_code'    => 'nullable|string|exists:territories,code',
            'address'           => 'nullable|string|max:255',
            'phone'             => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:255',
            'latitude'            => 'nullable|numeric|between:12,17|required_with:longitude',
            'longitude'           => 'nullable|numeric|between:-18,-11|required_with:latitude',
            'accepts_collections' => 'boolean',
        ];
    }
}

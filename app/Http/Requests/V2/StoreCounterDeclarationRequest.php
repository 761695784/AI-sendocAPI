<?php

namespace App\Http\Requests\V2;

use Illuminate\Foundation\Http\FormRequest;

class StoreCounterDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Victime existante (uuid) OU création à la volée (citizen.*).
            'citizen_profile_uuid'  => 'required_without:citizen|nullable|uuid|exists:citizen_profiles,uuid',
            'citizen'               => 'required_without:citizen_profile_uuid|array',
            'citizen.first_name'    => 'required_with:citizen|string|max:100',
            'citizen.last_name'     => 'required_with:citizen|string|max:100',
            'citizen.birth_date'    => 'nullable|date|before:today',
            'citizen.phone'         => 'required_with:citizen|string|max:20',
            'citizen.email'         => 'nullable|email|max:100',

            'lost_at'               => 'nullable|date|before_or_equal:today',
            'lost_place'            => 'nullable|string|max:255',
            'territory_code'        => 'nullable|string|exists:territories,code',
            'circumstances'         => 'nullable|string|max:2000',

            'items'                     => 'required|array|min:1|max:10',
            'items.*.document_type_id'  => 'required|integer|exists:document_types,id',
            'items.*.first_name'        => 'required|string|max:100',
            'items.*.last_name'         => 'required|string|max:100',
            'items.*.birth_date'        => 'nullable|date|before:today',
            'items.*.doc_number'        => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Indiquez au moins un document perdu.',
            'citizen_profile_uuid.required_without' => 'Sélectionnez la victime ou saisissez ses informations.',
        ];
    }
}

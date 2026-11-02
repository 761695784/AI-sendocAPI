<?php

namespace App\Http\Requests\V2;

use App\Models\Restitution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Certification au guichet d'une déclaration faite en ligne.
 * La personne présente est le titulaire du document : sa pièce est obligatoire.
 */
class CertifyOnlineDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $structure = \App\Http\Middleware\ResolveActiveOrganization::current();

        return $structure !== null
            && $this->user()->can('createAtCounter', [\App\Models\DeclarationDePerte::class, $structure->loadMissing('type')]);
    }

    public function rules(): array
    {
        return [
            'person'                        => 'required|array',
            'person.first_name'             => 'required|string|max:100',
            'person.last_name'              => 'required|string|max:100',
            'person.birth_date'             => 'nullable|date|before:today',
            'person.id_type'                => ['required', Rule::in(Restitution::ID_TYPES)],
            'person.id_number'              => 'required|string|min:4|max:50',
            'checks'                        => 'required|array',
            'checks.identity_photo_matches' => 'required|boolean',
            'name_override_reason'          => 'nullable|string|min:5|max:255',
            'lost_at'                       => 'nullable|date|before_or_equal:today',
            'lost_place'                    => 'nullable|string|max:255',
            'territory_code'                => 'nullable|string|exists:territories,code',
            'circumstances'                 => 'nullable|string|max:2000',
            'doc_number'                    => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'person.id_number.required' => 'La pièce d\'identité présentée est obligatoire.',
            'person.id_type.required'   => 'Indiquez le type de pièce présentée.',
        ];
    }
}

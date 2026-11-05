<?php

namespace App\Http\Requests\V2;

use App\Models\Restitution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Certification au guichet d'une déclaration faite en ligne.
 * La personne présente est le titulaire du document. Sa pièce d'identité est facultative :
 * une victime peut avoir perdu toutes ses pièces d'un coup (l'agent l'indique dans les circonstances).
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
            'person.id_type'                => ['nullable', 'required_with:person.id_number', Rule::in(Restitution::ID_TYPES)],
            'person.id_number'              => 'nullable|string|min:4|max:50',
            'checks'                        => 'nullable|array',
            'checks.identity_photo_matches' => 'nullable|boolean',
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
            'person.id_type.required_with' => 'Indiquez le type de la pièce présentée.',
        ];
    }
}

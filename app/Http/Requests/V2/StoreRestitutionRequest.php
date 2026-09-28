<?php

namespace App\Http\Requests\V2;

use App\Models\Restitution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestitutionRequest extends FormRequest
{
    /** Droits vérifiés AVANT la validation : un citoyen reçoit 403, pas 422. */
    public function authorize(): bool
    {
        $structure = \App\Http\Middleware\ResolveActiveOrganization::current();

        return $structure !== null
            && app(\App\Services\Access\OrganizationAccess::class)->can($this->user(), 'restitutions.perform', $structure);
    }

    public function rules(): array
    {
        $proxy = $this->input('recipient_type') === Restitution::RECIPIENT_PROXY;

        return [
            'recipient_type'          => ['required', Rule::in([Restitution::RECIPIENT_OWNER, Restitution::RECIPIENT_PROXY])],

            // Titulaire : sa pièce est exigée s'il vient lui-même.
            'owner'                   => 'required|array',
            'owner.first_name'        => 'required|string|max:100',
            'owner.last_name'         => 'required|string|max:100',
            'owner.birth_date'        => 'nullable|date|before:today',
            'owner.id_type'           => [$proxy ? 'nullable' : 'required', Rule::in(Restitution::ID_TYPES)],
            'owner.id_number'         => ($proxy ? 'nullable' : 'required') . '|string|min:4|max:50',
            'owner.phone'             => 'nullable|string|max:20',

            // Mandataire
            'proxy'                        => $proxy ? 'required|array' : 'prohibited',
            'proxy.first_name'             => $proxy ? 'required|string|max:100' : 'nullable',
            'proxy.last_name'              => $proxy ? 'required|string|max:100' : 'nullable',
            'proxy.id_type'                => $proxy ? ['required', Rule::in(Restitution::ID_TYPES)] : 'nullable',
            'proxy.id_number'              => $proxy ? 'required|string|min:4|max:50' : 'nullable',
            'proxy.phone'                  => 'nullable|string|max:20',
            'proxy.relationship'           => 'nullable|string|max:60',
            'proxy.power_of_attorney_ref'  => 'nullable|string|max:100',

            // Contrôles certifiés par l'agent
            'checks'                             => 'required|array',
            'checks.identity_photo_matches'      => 'required|boolean',
            'checks.power_of_attorney_verified'  => 'sometimes|boolean',

            'name_override_reason'    => 'nullable|string|min:5|max:255',
        ];
    }
}

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
            // Pièce présentée scannée (POST /api/v2/ocr/scan, purpose=identity_check) : type et numéro lus automatiquement.
            'owner.ocr_scan_id'       => 'nullable|uuid',
            'owner.id_type'           => [$proxy ? 'nullable' : 'required_without:owner.ocr_scan_id', 'nullable', Rule::in(Restitution::ID_TYPES)],
            'owner.id_number'         => ($proxy ? 'nullable' : 'required_without:owner.ocr_scan_id') . '|nullable|string|min:4|max:50',
            'owner.phone'             => 'nullable|string|max:20',

            // Mandataire
            'proxy'                        => $proxy ? 'required|array' : 'prohibited',
            'proxy.first_name'             => $proxy ? 'required|string|max:100' : 'nullable',
            'proxy.last_name'              => $proxy ? 'required|string|max:100' : 'nullable',
            'proxy.ocr_scan_id'            => 'nullable|uuid',
            'proxy.id_type'                => $proxy ? ['required_without:proxy.ocr_scan_id', 'nullable', Rule::in(Restitution::ID_TYPES)] : 'nullable',
            'proxy.id_number'              => $proxy ? 'required_without:proxy.ocr_scan_id|nullable|string|min:4|max:50' : 'nullable',
            'proxy.phone'                  => 'nullable|string|max:20',
            'proxy.relationship'           => 'nullable|string|max:60',
            'proxy.power_of_attorney_ref'  => 'nullable|string|max:100',

            // Contrôles certifiés par l'agent
            'checks'                             => 'required|array',
            'checks.identity_photo_matches'      => 'required|boolean',
            'checks.power_of_attorney_verified'  => 'sometimes|boolean',

            'name_override_reason'    => 'nullable|string|min:5|max:255',
            // Alerte de vigilance haute ouverte sur le document : accord motivé d'un responsable.
            'vigilance_override_reason' => 'nullable|string|min:5|max:255',
        ];
    }
}

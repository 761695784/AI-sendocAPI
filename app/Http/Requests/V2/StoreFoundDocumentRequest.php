<?php

namespace App\Http\Requests\V2;

use App\Http\Middleware\ResolveActiveOrganization;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreFoundDocumentRequest extends FormRequest
{
    /** Droits vérifiés AVANT la validation : un citoyen reçoit 403, pas 422. */
    public function authorize(): bool
    {
        $structure = ResolveActiveOrganization::current();
        $structure?->loadMissing('type');

        return $structure !== null
            && Gate::allows('depositAtCounter', [Document::class, $structure]);
    }

    public function rules(): array
    {
        return [
            'document_type_id' => 'required|integer|exists:document_types,id',
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'birth_date'       => 'nullable|date|before:today',
            'doc_number'       => 'nullable|string|max:50',
            'found_at'         => 'nullable|date|before_or_equal:today',
            'found_place'      => 'nullable|string|max:255',
            'territory_code'   => 'nullable|string|exists:territories,code',
            'image'            => 'nullable|file|mimes:jpeg,png,jpg,webp|max:5120',

            // Trouveur (facultatif) : pour le remercier / le recontacter.
            'finder'            => 'nullable|array',
            'finder.first_name' => 'required_with:finder|string|max:100',
            'finder.last_name'  => 'required_with:finder|string|max:100',
            'finder.phone'      => 'required_with:finder|string|max:20',
        ];
    }
}

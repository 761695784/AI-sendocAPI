<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            // Images uniquement : la collection MediaLibrary n'accepte pas les PDF.
            'image'             => 'required|file|mimes:jpeg,png,jpg,webp|max:5120', // 5 Mo
            'OwnerFirstName'    => 'required|string|max:255',
            'OwnerLastName'     => 'required|string|max:255',
            'DocIdentification' => 'nullable|string|max:50',
            // Conservé pour compatibilité avec le front ; toujours "non récupéré" à la création.
            'statut'            => 'sometimes|in:récupéré,non récupéré',
            'Location'          => 'required|string|max:255',
            'document_type_id'  => 'required|exists:document_types,id',

            // Lieu précis de la trouvaille (GPS du téléphone, facultatif) et département.
            'latitude'          => 'nullable|numeric|between:12,17|required_with:longitude',
            'longitude'         => 'nullable|numeric|between:-18,-11|required_with:latitude',
            'territory_code'    => 'nullable|string|exists:territories,code',

            // « J'accepte d'être contacté par la structure la plus proche pour lui remettre le document. »
            'contact_consent'   => 'sometimes|boolean',
            'contact_phone'     => 'nullable|string|max:20|regex:/^[0-9+\s().-]{7,20}$/',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('contact_consent') && ! $this->input('contact_phone') && ! $this->user()?->Phone) {
                $validator->errors()->add('contact_phone', 'Indiquez un numéro pour que la structure puisse vous joindre.');
            }
        });
    }
}

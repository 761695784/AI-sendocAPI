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
        ];
    }
}

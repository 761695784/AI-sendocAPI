<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Vérifie si l'utilisateur est authentifié
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => 'required|file|mimes:jpeg,png,jpg,pdf|max:5120', // 5 Mo
            'OwnerFirstName' => 'required|string|max:255',
            'OwnerLastName' => 'required|string|max:255',
            'statut' => 'required|in:récupéré,non récupéré',
            'Location' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id', // Valider que le type de document existe
        ];
    }
}

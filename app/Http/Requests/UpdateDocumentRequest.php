<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
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
            // Les règles ici sont optionnelles sauf pour le statut
            'image' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:2048',
            'OwnerFirstName' => 'nullable|string|max:255',
            'OwnerLastName' => 'nullable|string|max:255',
            'statut' => 'required|in:récupéré,non récupéré', // Le seul champ obligatoire
            'Location' => 'nullable|string|max:255',
            'document_type_id' => 'nullable|exists:document_types,id', // Optionnel mais doit exister si fourni
        ];
    }
}

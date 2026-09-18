<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentaireRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check(); // Autoriser uniquement les utilisateurs authentifiés
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'contenu' => 'required|string|max:500',
            'document_id' => 'required|exists:documents,id', // Assurez-vous que le document existe
        ];
    }
}

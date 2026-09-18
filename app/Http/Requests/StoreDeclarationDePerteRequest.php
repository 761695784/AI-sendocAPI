<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeclarationDePerteRequest extends FormRequest
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
            'Title' => 'required|string|max:255',
            'FirstNameInDoc' => 'required|string',
            'LastNameInDoc' => 'required|string',
            'document_type_id' => 'required',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreDeclarationDePerteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'Title'             => 'required|string|max:255',
            'FirstNameInDoc'    => 'required|string|max:100',
            'LastNameInDoc'     => 'required|string|max:100',
            'DocIdentification' => 'nullable|string|max:50',
            'document_type_id'  => 'required|exists:document_types,id',
        ];
    }
}

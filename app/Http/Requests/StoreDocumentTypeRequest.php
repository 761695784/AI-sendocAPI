<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'TypeName'           => 'required|string|max:255|unique:document_types,TypeName',
            'IdentificationSize' => 'required|string|max:255',
        ];
    }
}

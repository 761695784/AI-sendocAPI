<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'TypeName'           => ['sometimes', 'required', 'string', 'max:255',
                Rule::unique('document_types', 'TypeName')->ignore($this->route('document_type'))],
            'IdentificationSize' => 'sometimes|required|string|max:255',
            'allows_proxy'       => 'sometimes|boolean',
        ];
    }
}

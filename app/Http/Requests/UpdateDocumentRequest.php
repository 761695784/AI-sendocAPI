<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'image'             => 'nullable|file|mimes:jpeg,png,jpg,webp|max:5120',
            'OwnerFirstName'    => 'nullable|string|max:255',
            'OwnerLastName'     => 'nullable|string|max:255',
            'DocIdentification' => 'nullable|string|max:50',
            'statut'            => 'required|in:récupéré,non récupéré',
            'Location'          => 'nullable|string|max:255',
            'document_type_id'  => 'nullable|exists:document_types,id',
        ];
    }
}

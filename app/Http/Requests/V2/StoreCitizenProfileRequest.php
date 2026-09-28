<?php

namespace App\Http\Requests\V2;

use Illuminate\Foundation\Http\FormRequest;

class StoreCitizenProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'birth_date' => 'nullable|date|before:today',
            'phone'      => 'required|string|max:20',
            'email'      => 'nullable|email|max:100',
        ];
    }
}

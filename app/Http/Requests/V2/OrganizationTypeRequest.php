<?php

namespace App\Http\Requests\V2;

use App\Models\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $type     = $this->route('organizationType');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code'  => [$required, 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('organization_types', 'code')->ignore($type?->id)],
            'name'  => [$required, 'string', 'max:255'],
            'level' => [$required, Rule::in(OrganizationType::LEVELS)],
        ];
    }
}

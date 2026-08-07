<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('tenants', 'subdomain')->ignore($this->route('tenant')),
            ],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'status' => ['sometimes', 'required', 'string', 'in:active,suspended'],
        ];
    }
}

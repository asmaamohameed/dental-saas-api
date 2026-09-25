<?php

namespace App\Http\Requests\V1\Patient;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                Rule::unique('patients', 'phone')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female'],
            'medical_history' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}

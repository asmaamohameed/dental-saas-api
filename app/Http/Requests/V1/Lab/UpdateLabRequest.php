<?php

namespace App\Http\Requests\V1\Lab;

use App\Support\LabWorks\LabFields;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lab'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('labs', 'name')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->ignore($this->route('lab')),
            ],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'default_turnaround_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return LabFields::lab();
    }
}

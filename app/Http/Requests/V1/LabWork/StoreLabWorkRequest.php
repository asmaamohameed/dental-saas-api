<?php

namespace App\Http\Requests\V1\LabWork;

use App\Models\LabWork;
use App\Support\LabWorks\LabFields;
use App\Support\LabWorks\LabWorkTiming;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LabWork::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('description')) {
            $this->merge([
                'description' => trim((string) $this->input('description')),
            ]);
        }

        if (! $this->filled('sent_on')) {
            $this->merge([
                'sent_on' => LabWorkTiming::todayDateString(),
            ]);
        }
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'patient_id' => [
                'required',
                'uuid',
                Rule::exists('patients', 'id')->where('tenant_id', $tenantId),
            ],
            'lab_id' => [
                'required',
                'uuid',
                Rule::exists('labs', 'id')->where('tenant_id', $tenantId),
            ],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'sent_on' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:sent_on'],
        ];
    }

    public function attributes(): array
    {
        return LabFields::work();
    }
}

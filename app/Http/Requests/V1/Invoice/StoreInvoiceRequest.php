<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\Appointment;
use App\Models\PatientTreatment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid', Rule::exists('patients', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'appointment_id' => ['nullable', 'uuid', Rule::exists('appointments', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.patient_treatment_id' => ['nullable', 'uuid', Rule::exists('patient_treatments', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tenantId = app(CurrentTenant::class)->id();

            if ($this->filled('appointment_id') && $this->filled('patient_id')) {
                $appointment = Appointment::find($this->input('appointment_id'));

                if ($appointment && (string) $appointment->patient_id !== (string) $this->input('patient_id')) {
                    $validator->errors()->add('appointment_id', 'The appointment does not belong to this patient.');
                }
            }

            $items = $this->input('items', []);

            foreach ($items as $index => $item) {
                $hasTreatment = ! empty($item['patient_treatment_id']);

                if ($hasTreatment) {
                    $treatment = PatientTreatment::where('tenant_id', $tenantId)->find($item['patient_treatment_id']);
                    if ($treatment && (string) $treatment->patient_id !== (string) $this->input('patient_id')) {
                        $validator->errors()->add("items.{$index}.patient_treatment_id", 'The selected treatment does not belong to this patient.');
                    }
                } elseif (! isset($item['price'])) {
                    $validator->errors()->add("items.{$index}.price", 'Price is required when no treatment is selected.');
                }

                if (! $hasTreatment) {
                    $description = trim((string) ($item['description'] ?? ''));
                    if (mb_strlen($description) < 5) {
                        $validator->errors()->add(
                            "items.{$index}.description",
                            'A note of at least 5 characters is required when no treatment is selected.'
                        );
                    }
                }
            }
        });
    }
}

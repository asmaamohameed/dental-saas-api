<?php

namespace App\Http\Requests\V1\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\PatientTreatment;
use App\Models\Service;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', 'required', 'uuid', Rule::exists('patients', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.service_id' => ['nullable', 'uuid', Rule::exists('services', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items.*.patient_treatment_id' => ['nullable', 'uuid', Rule::exists('patient_treatments', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tenantId = app(CurrentTenant::class)->id();
            $invoice = $this->route('invoice');

            if ($this->has('appointment_id')) {
                $validator->errors()->add(
                    'appointment_id',
                    'The appointment linked to an invoice cannot be changed after creation.'
                );
            }

            if ($invoice && $invoice->status === InvoiceStatus::PAID) {
                $validator->errors()->add('invoice', 'Fully paid invoices cannot be updated.');
            }

            if (
                $invoice && $invoice->status === InvoiceStatus::PARTIAL && $this->filled('patient_id')
                && (string) $this->input('patient_id') !== (string) $invoice->patient_id
            ) {
                $validator->errors()->add(
                    'patient_id',
                    'The patient on an invoice with recorded payments cannot be changed.'
                );
            }

            $appointmentId = $invoice?->appointment_id;
            $patientId = $this->filled('patient_id') ? $this->input('patient_id') : $invoice?->patient_id;

            if ($appointmentId && $patientId) {
                $appointment = Appointment::where('tenant_id', $tenantId)->find($appointmentId);
                if ($appointment && (string) $appointment->patient_id !== (string) $patientId) {
                    $validator->errors()->add('patient_id', 'The new patient does not match the invoice\'s appointment.');
                }
            }

            $items = $this->input('items', []);
            foreach ($items as $index => $item) {
                if (! empty($item['service_id'])) {
                    $service = Service::where('tenant_id', $tenantId)->find($item['service_id']);
                    if ($service && $service->is_other) {
                        if (empty($item['description'])) {
                            $validator->errors()->add(
                                "items.{$index}.description",
                                'Description is required when selecting the "Other" service.'
                            );
                        }
                        if (! isset($item['price'])) {
                            $validator->errors()->add(
                                "items.{$index}.price",
                                'Price is required when selecting the "Other" service.'
                            );
                        }
                    }
                }

                if (! empty($item['patient_treatment_id'])) {
                    $treatment = PatientTreatment::where('tenant_id', $tenantId)->find($item['patient_treatment_id']);
                    if ($treatment && $patientId && (string) $treatment->patient_id !== (string) $patientId) {
                        $validator->errors()->add(
                            "items.{$index}.patient_treatment_id",
                            'The selected treatment does not belong to this patient.'
                        );
                    }
                }
            }
        });
    }
}

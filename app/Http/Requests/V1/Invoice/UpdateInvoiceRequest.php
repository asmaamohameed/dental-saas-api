<?php

namespace App\Http\Requests\V1\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\PatientTreatment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->has('items')) {
            return true;
        }

        /** @var Invoice|null $invoice */
        $invoice = $this->route('invoice');

        foreach ($this->input('items', []) as $item) {
            if (empty($item['patient_treatment_id']) && array_key_exists('price', $item)) {
                $requiresChangePrice = true;

                if (! empty($item['id']) && $invoice) {
                    $existing = $invoice->items()->find($item['id']);

                    if ($existing && (string) $existing->invoice_id === (string) $invoice->id) {
                        if (bccomp((string) $item['price'], (string) $existing->price, 2) === 0) {
                            $requiresChangePrice = false;
                        }
                    }
                }

                if ($requiresChangePrice && ! ($this->user()?->can('canChangePrice') ?? false)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', 'required', 'uuid', Rule::exists('patients', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.id' => [
                'nullable',
                'uuid',
                Rule::exists('invoice_items', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
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

            if ($invoice && $invoice->status === InvoiceStatus::REFUNDED) {
                $validator->errors()->add('invoice', 'A refunded invoice cannot be modified.');
            }

            if ($invoice && $invoice->status === InvoiceStatus::PAID) {
                $scalarKeys = array_diff(array_keys($this->all()), ['items']);
                if ($scalarKeys !== []) {
                    $validator->errors()->add('invoice', 'Fully paid invoices cannot be updated.');
                }
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
                $hasTreatment = ! empty($item['patient_treatment_id']);

                if ($hasTreatment) {
                    $treatment = PatientTreatment::where('tenant_id', $tenantId)->find($item['patient_treatment_id']);
                    if ($treatment && $patientId && (string) $treatment->patient_id !== (string) $patientId) {
                        $validator->errors()->add(
                            "items.{$index}.patient_treatment_id",
                            'The selected treatment does not belong to this patient.'
                        );
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

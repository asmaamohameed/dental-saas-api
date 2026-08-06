<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', 'required', 'uuid', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.service_id' => ['required_with:items', 'uuid', 'exists:services,id'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Invoice $invoice */
            $invoice = $this->route('invoice');

            if ($invoice && $invoice->status === 'paid') {
                $validator->errors()->add('invoice', 'Fully paid invoices cannot be updated.');
            }

            $user = $this->user();
            if ($user && $user->role === 'receptionist') {
                if ($invoice && $invoice->status === 'partial' && $this->has('items')) {
                    $validator->errors()->add('items', 'Receptionists cannot modify items of partially paid invoices.');
                }
            }

            $items = $this->input('items', []);
            foreach ($items as $index => $item) {
                if (! empty($item['service_id'])) {
                    $service = Service::find($item['service_id']);
                    if ($service && $service->is_other && empty($item['description'])) {
                        $validator->errors()->add(
                            "items.{$index}.description",
                            'Description is required when selecting the "Other" service.'
                        );
                    }
                }
            }
        });
    }
}

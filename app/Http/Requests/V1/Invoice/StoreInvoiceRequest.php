<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'uuid', 'exists:services,id'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
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

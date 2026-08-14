<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\Service;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                'uuid',
                Rule::exists('services', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $serviceId = $this->input('service_id');
            if ($serviceId) {
                $service = Service::where('tenant_id', app(CurrentTenant::class)->id())->find($serviceId);
                if ($service && $service->is_other && empty($this->input('description'))) {
                    $validator->errors()->add('description', 'Description is required when selecting the "Other" service.');
                }
            }
        });
    }
}

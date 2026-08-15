<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }

    public function rules(): array
    {
        return [
            'service_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('services', 'id')
                    ->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $serviceId = $this->input('service_id');

            if (! $serviceId) {
                $item = $this->route('item');
                $serviceId = $item instanceof InvoiceItem ? $item->service_id : null;
            }

            if ($serviceId) {
                $service = Service::where('tenant_id', app(CurrentTenant::class)->id())->find($serviceId);

                $description = $this->has('description')
                    ? $this->input('description')
                    : ($this->route('item')?->description);

                if ($service && $service->is_other && empty($description)) {
                    $validator->errors()->add('description', 'Description is required when selecting the "Other" service.');
                }
            }
        });
    }
}

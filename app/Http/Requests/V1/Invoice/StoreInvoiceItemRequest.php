<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\InvoiceItem;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [InvoiceItem::class, $this->route('invoice')]);
    }

    public function rules(): array
    {
        return [
            'patient_treatment_id' => [
                'nullable',
                'uuid',
                Rule::exists('patient_treatments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('patient_treatment_id')) {
                return;
            }

            if (! $this->has('price')) {
                $validator->errors()->add('price', 'Price is required when no treatment is selected.');
            }

            $description = trim((string) ($this->input('description') ?? ''));
            if (mb_strlen($description) < 5) {
                $validator->errors()->add(
                    'description',
                    'A note of at least 5 characters is required when no treatment is selected.'
                );
            }
        });
    }
}

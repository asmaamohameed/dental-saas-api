<?php

namespace App\Http\Requests\V1\Invoice;

use App\Models\InvoiceItem;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('update', $this->route('item'))) {
            return false;
        }

        /** @var InvoiceItem $item */
        $item = $this->route('item');
        $treatmentId = $this->input('patient_treatment_id', $item->patient_treatment_id);

        if ($this->has('price') && empty($treatmentId)) {
            if (bccomp((string) $this->input('price'), (string) $item->price, 2) !== 0) {
                return $this->user()->can('canChangePrice');
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'patient_treatment_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('patient_treatments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}

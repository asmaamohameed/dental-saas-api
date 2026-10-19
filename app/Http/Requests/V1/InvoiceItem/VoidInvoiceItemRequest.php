<?php

namespace App\Http\Requests\V1\InvoiceItem;

use Illuminate\Foundation\Http\FormRequest;

class VoidInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('item'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}

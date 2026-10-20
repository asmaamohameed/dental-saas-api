<?php

namespace App\Http\Requests\V1\InvoiceItem;

use Illuminate\Foundation\Http\FormRequest;

class RepriceDoneItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('repriceDone', $this->route('item'));
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}

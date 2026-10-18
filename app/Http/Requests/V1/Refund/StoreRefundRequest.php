<?php

namespace App\Http\Requests\V1\Refund;

use App\Enums\RefundReasonCode;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('refund', [Payment::class, $this->route('invoice')]);
    }

    public function rules(): array
    {
        return [
            'invoice_item_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', Rule::in(RefundReasonCode::values())],
            'notes' => ['nullable', 'string', 'max:500'],
            'refunded_payment_id' => ['nullable', 'uuid'],
            'method' => ['nullable', 'string', 'in:cash,card,transfer,other'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('reason') === RefundReasonCode::Other->value && blank($this->input('notes'))) {
                $validator->errors()->add('notes', 'Please specify the reason.');
            }
        });
    }
}

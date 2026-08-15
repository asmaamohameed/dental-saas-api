<?php

namespace App\Http\Requests\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', $this->route('invoice'));
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'method' => ['required', 'string', 'in:cash,card,transfer,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');

            if ($invoice) {
                $amount = (float) $this->input('amount', 0);
                $remaining = (float) $invoice->remaining_amount;

                if ($amount > $remaining) {
                    $validator->errors()->add(
                        'amount',
                        "Payment amount ({$amount}) exceeds the remaining invoice balance ({$remaining})."
                    );
                }
            }
        });
    }
}

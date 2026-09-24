<?php

namespace App\Http\Requests\V1\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Payment::class, $this->route('invoice')]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
            'deduct_amount' => ['nullable', 'numeric', 'min:0'],
            'deduct_reason' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'method' => ['required', 'string', 'in:cash,card,transfer,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');
            $amount = (float) $this->input('amount', 0);
            $deduct = (float) $this->input('deduct_amount', 0);

            if ($amount + $deduct <= 0) {
                $validator->errors()->add(
                    'amount',
                    'Enter a payment amount or a deduct amount greater than zero.'
                );
            }

            if ($deduct > 0 && blank($this->input('deduct_reason'))) {
                $validator->errors()->add(
                    'deduct_reason',
                    'A deduct reason is required when deducting from the invoice.'
                );
            }

            if ($invoice) {
                $applied = $amount + $deduct;
                $remaining = (float) $invoice->remaining_amount;

                if ($applied > $remaining + 0.0001) {
                    $validator->errors()->add(
                        'amount',
                        "Payment plus deduct ({$applied}) exceeds the remaining invoice balance ({$remaining})."
                    );
                }
            }
        });
    }
}

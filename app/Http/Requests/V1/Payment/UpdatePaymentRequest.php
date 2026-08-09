<?php

namespace App\Http\Requests\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['sometimes', 'required', 'string', 'in:cash,card,transfer,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');
            $payment = $this->route('payment');

            if ($invoice && $payment && $this->has('amount')) {
                $newAmount = (float) $this->input('amount');
                $otherPaymentsTotal = (float) $invoice->payments()
                    ->where('id', '!=', $payment->id)
                    ->sum('amount');

                $maxAllowed = (float) $invoice->total_amount - $otherPaymentsTotal;

                if ($newAmount > $maxAllowed) {
                    $validator->errors()->add(
                        'amount',
                        "Updated payment amount ({$newAmount}) exceeds max allowable balance ({$maxAllowed})."
                    );
                }
            }
        });
    }
}

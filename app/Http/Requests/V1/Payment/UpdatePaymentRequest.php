<?php

namespace App\Http\Requests\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'deduct_amount' => ['nullable', 'numeric', 'min:0'],
            'deduct_reason' => ['nullable', 'string', 'max:500'],
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

            if (! $invoice || ! $payment) {
                return;
            }

            $newAmount = (float) $this->input('amount', $payment->amount);
            $newDeduct = (float) $this->input('deduct_amount', $payment->deduct_amount ?? 0);
            $newReason = $this->input('deduct_reason', $payment->deduct_reason);

            if ($newAmount + $newDeduct <= 0) {
                $validator->errors()->add(
                    'amount',
                    'Enter a payment amount or a deduct amount greater than zero.'
                );
            }

            if ($newDeduct > 0 && blank($newReason)) {
                $validator->errors()->add(
                    'deduct_reason',
                    'A deduct reason is required when deducting from the invoice.'
                );
            }

            $otherApplied = (float) $invoice->payments()
                ->where('id', '!=', $payment->id)
                ->get()
                ->sum(fn ($other) => (float) $other->amount + (float) ($other->deduct_amount ?? 0));

            $maxAllowed = (float) $invoice->total_amount - $otherApplied;

            if ($newAmount + $newDeduct > $maxAllowed + 0.0001) {
                $validator->errors()->add(
                    'amount',
                    "Updated payment plus deduct exceeds max allowable balance ({$maxAllowed})."
                );
            }
        });
    }
}

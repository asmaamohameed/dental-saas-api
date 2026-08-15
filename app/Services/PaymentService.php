<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function create(Invoice $invoice, array $data, string $receivedBy): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $receivedBy) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            $amount = (string) $data['amount'];
            $remaining = (string) $lockedInvoice->remaining_amount;

            if (bccomp($amount, $remaining, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Payment amount ({$amount}) exceeds the remaining invoice balance ({$remaining}).",
                ]);
            }

            /** @var Payment $payment */
            $payment = $lockedInvoice->payments()->create([
                ...$data,
                'paid_at' => $data['paid_at'] ?? Carbon::now(),
                'received_by' => $receivedBy,
            ]);

            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $payment->load(['receiver', 'invoice']);
        });
    }

    public function update(Invoice $invoice, Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $payment, $data) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            $changesAmount = array_key_exists('amount', $data);

            if ($changesAmount || $lockedInvoice->status === InvoiceStatus::PAID) {
                if ($lockedInvoice->status === InvoiceStatus::PAID && $changesAmount) {
                    throw ValidationException::withMessages([
                        'amount' => 'Cannot change the amount of a payment on a fully paid invoice. Record a reversal instead.',
                    ]);
                }
            }

            if ($changesAmount) {
                $otherPaymentsTotal = (string) $lockedInvoice->payments()
                    ->where('id', '!=', $payment->id)
                    ->sum('amount');

                $maxAllowed = bcsub((string) $lockedInvoice->total_amount, $otherPaymentsTotal, 2);
                $newAmount = (string) $data['amount'];

                if (bccomp($newAmount, $maxAllowed, 2) === 1) {
                    throw ValidationException::withMessages([
                        'amount' => "Updated payment amount ({$newAmount}) exceeds max allowable balance ({$maxAllowed}).",
                    ]);
                }
            }

            $payment->update($data);

            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $payment->load(['receiver', 'invoice']);
        });
    }

    public function delete(Invoice $invoice, Payment $payment): bool
    {
        return DB::transaction(function () use ($invoice, $payment) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cannot delete a payment from a fully paid invoice. Record a reversal instead.',
                ]);
            }

            $deleted = (bool) $payment->delete();

            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $deleted;
        });
    }
}

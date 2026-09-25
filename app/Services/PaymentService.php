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

            $amount = (string) ($data['amount'] ?? 0);
            $deduct = (string) ($data['deduct_amount'] ?? 0);
            $applied = bcadd($amount, $deduct, 2);
            $remaining = (string) $lockedInvoice->remaining_amount;

            if (bccomp($applied, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter a payment amount or a deduct amount greater than zero.',
                ]);
            }

            if (bccomp($applied, $remaining, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Payment plus deduct ({$applied}) exceeds the remaining invoice balance ({$remaining}).",
                ]);
            }

            if (bccomp($deduct, '0', 2) === 1 && empty($data['deduct_reason'])) {
                throw ValidationException::withMessages([
                    'deduct_reason' => 'A deduct reason is required when deducting from the invoice.',
                ]);
            }

            /** @var Payment $payment */
            $payment = $lockedInvoice->payments()->create([
                ...$data,
                'amount' => $amount,
                'deduct_amount' => $deduct,
                'deduct_reason' => $data['deduct_reason'] ?? null,
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

            if ($changesAmount || array_key_exists('deduct_amount', $data) || $lockedInvoice->status === InvoiceStatus::PAID) {
                if ($lockedInvoice->status === InvoiceStatus::PAID && ($changesAmount || array_key_exists('deduct_amount', $data))) {
                    throw ValidationException::withMessages([
                        'amount' => 'Cannot change the amount of a payment on a fully paid invoice. Record a reversal instead.',
                    ]);
                }
            }

            if ($changesAmount || array_key_exists('deduct_amount', $data)) {
                $otherPayments = $lockedInvoice->payments()->where('id', '!=', $payment->id)->get();
                $otherApplied = (string) $otherPayments->sum(function (Payment $other) {
                    return (float) $other->amount + (float) ($other->deduct_amount ?? 0);
                });

                $maxAllowed = bcsub((string) $lockedInvoice->total_amount, $otherApplied, 2);
                $newAmount = (string) ($data['amount'] ?? $payment->amount);
                $newDeduct = (string) ($data['deduct_amount'] ?? $payment->deduct_amount ?? 0);
                $newApplied = bcadd($newAmount, $newDeduct, 2);

                if (bccomp($newApplied, $maxAllowed, 2) === 1) {
                    throw ValidationException::withMessages([
                        'amount' => "Updated payment plus deduct ({$newApplied}) exceeds max allowable balance ({$maxAllowed}).",
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

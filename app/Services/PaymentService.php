<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Enums\RefundReasonCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $lockedInvoice->forgetBalance();

            $amount = $this->money($data['amount'] ?? 0);
            $deduct = $this->money($data['deduct_amount'] ?? 0);
            $applied = bcadd($amount, $deduct, 2);
            $remaining = $this->money($lockedInvoice->remaining_amount);

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
                'type' => PaymentType::Payment,
                'amount' => $amount,
                'deduct_amount' => $deduct,
                'deduct_reason' => $data['deduct_reason'] ?? null,
                'paid_at' => $data['paid_at'] ?? Carbon::now(),
                'method' => $data['method'],
                'notes' => $data['notes'] ?? null,
                'received_by' => $receivedBy,
            ]);

            $lockedInvoice->forgetBalance();
            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $payment->load(['receiver', 'invoice']);
        });
    }

    /**
     * Append a refund row. Amount is always positive; type decides direction.
     * The linked treatment's clinical status is not changed.
     */
    public function refund(Invoice $invoice, array $data, string $receivedBy): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $receivedBy) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $lockedInvoice->forgetBalance();

            $amount = $this->money($data['amount'] ?? 0);
            $reason = trim((string) ($data['reason'] ?? ''));

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required for a refund.',
                ]);
            }

            if (bccomp($amount, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    'amount' => 'Refund amount must be greater than zero.',
                ]);
            }

            /** @var InvoiceItem|null $item */
            $item = $lockedInvoice->items()->withTrashed()->whereKey($data['invoice_item_id'] ?? null)->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'invoice_item_id' => 'Choose an item on this invoice.',
                ]);
            }

            if (! empty($data['refunded_payment_id'])) {
                $original = $lockedInvoice->payments()->whereKey($data['refunded_payment_id'])->first();

                if (! $original) {
                    throw ValidationException::withMessages([
                        'refunded_payment_id' => 'The original payment does not belong to this invoice.',
                    ]);
                }
            }

            $netPaid = $this->money($lockedInvoice->netPaid());
            $alreadyRefunded = $this->money(
                $lockedInvoice->refunds()->where('invoice_item_id', $item->id)->sum('amount')
            );
            $itemRemaining = bcsub($this->money($item->subtotal), $alreadyRefunded, 2);

            if (bccomp($itemRemaining, '0', 2) === -1) {
                $itemRemaining = '0.00';
            }

            if (bccomp($amount, $netPaid, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Refund ({$amount}) exceeds the net amount paid on this invoice ({$netPaid}).",
                ]);
            }

            if (bccomp($amount, $itemRemaining, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Refund ({$amount}) exceeds the remaining refundable amount for this item ({$itemRemaining}).",
                ]);
            }

            if ($item->trashed() || $item->voided_at !== null) {
                $refundDue = $lockedInvoice->invoiceBalance()->refundDue;

                if (bccomp($amount, $refundDue, 2) === 1) {
                    throw ValidationException::withMessages([
                        'amount' => "Refund ({$amount}) exceeds the refund due on this invoice ({$refundDue}).",
                    ]);
                }
            }

            /** @var Payment $refund */
            $refund = $lockedInvoice->refunds()->create([
                'type' => PaymentType::Refund,
                'invoice_item_id' => $item->id,
                'refunded_payment_id' => $data['refunded_payment_id'] ?? null,
                'amount' => $amount,
                'deduct_amount' => 0,
                'deduct_reason' => null,
                'reason' => $reason,
                'notes' => ($reason === RefundReasonCode::Other->value) ? ($data['notes'] ?? null) : null,
                'paid_at' => Carbon::now(),
                'method' => $data['method'] ?? 'cash',
                'received_by' => $receivedBy,
            ]);

            $lockedInvoice->forgetBalance();
            $this->invoiceService->recalculateStatus($lockedInvoice);

            $refund->setRelation('invoiceItem', $item);

            return $refund->load(['receiver', 'invoice.patient']);
        });
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}

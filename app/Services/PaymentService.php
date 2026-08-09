<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function create(Invoice $invoice, array $data, string $receivedBy): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $receivedBy) {
            /** @var Payment $payment */
            $payment = $invoice->payments()->create([
                ...$data,
                'paid_at' => $data['paid_at'] ?? Carbon::now(),
                'received_by' => $receivedBy,
            ]);

            $this->invoiceService->recalculateStatus($invoice);

            return $payment->load(['receiver', 'invoice']);
        });
    }

    public function update(Invoice $invoice, Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $payment, $data) {
            $payment->update($data);

            $this->invoiceService->recalculateStatus($invoice);

            return $payment->load(['receiver', 'invoice']);
        });
    }

    public function delete(Invoice $invoice, Payment $payment): bool
    {
        return DB::transaction(function () use ($invoice, $payment) {
            $deleted = (bool) $payment->delete();

            $this->invoiceService->recalculateStatus($invoice);

            return $deleted;
        });
    }
}

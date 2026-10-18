<?php

namespace App\Billing\Actions;

use App\Billing\Queries\InvoiceItemStateSql;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of invoices.total_amount.
 * Caller must already be inside a transaction that holds the invoice row lock.
 */
final class RecomputeInvoiceTotal
{
    public function __construct(
        private readonly InvoiceItemStateSql $itemStateSql,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function handle(Invoice $invoice): Invoice
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('RecomputeInvoiceTotal requires an open transaction with the invoice row locked.');
        }

        $total = bcadd($this->itemStateSql->totalsForInvoice((string) $invoice->id)['total'], '0', 2);
        $invoice->update(['total_amount' => $total]);
        $this->invoiceService->recalculateStatus($invoice);

        return $invoice;
    }
}

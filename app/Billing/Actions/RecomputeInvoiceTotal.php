<?php

namespace App\Billing\Actions;

use App\Billing\Queries\InvoiceItemStateSql;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of invoices.total_amount.
 * Acquires a row lock on the invoice (starts a transaction when none is open).
 */
final class RecomputeInvoiceTotal
{
    public function __construct(
        private readonly InvoiceItemStateSql $itemStateSql,
    ) {}

    public function handle(Invoice $invoice): Invoice
    {
        $recompute = function () use ($invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $total = bcadd($this->itemStateSql->totalsForInvoice((string) $locked->id)['total'], '0', 2);
            $locked->update(['total_amount' => $total]);

            app(InvoiceService::class)->recalculateStatus($locked);

            return $locked->fresh();
        };

        if (DB::transactionLevel() >= 1) {
            return $recompute();
        }

        return DB::transaction($recompute);
    }
}

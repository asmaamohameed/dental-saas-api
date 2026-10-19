<?php

namespace App\Billing\Actions;

use App\Billing\Audit\BillingAuditLogger;
use App\Billing\Domain\Exceptions\CannotVoidInvoiceItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Enums\AuditAction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use InvalidArgumentException;

final class VoidInvoiceItem
{
    public function __construct(
        private readonly InvoiceItemFactsReader $factsReader,
        private readonly InvoiceItemStateResolver $stateResolver,
        private readonly BillingAuditLogger $auditLogger,
        private readonly RecomputeInvoiceTotal $recomputeInvoiceTotal,
    ) {}

    public function handle(Invoice $invoice, InvoiceItem $item, User $actor, string $reason): InvoiceItem
    {
        $this->assertItemOnInvoice($invoice, $item);
        InvoiceClosedException::throwIf($invoice->status);

        $reason = trim($reason);
        if ($reason === '') {
            throw new CannotVoidInvoiceItem('A reason is required to void a line.');
        }

        $state = $this->stateResolver->resolve($this->factsReader->read($item));
        if ($state !== InvoiceItemState::DONE) {
            throw CannotVoidInvoiceItem::notDone();
        }

        $old = [
            'price' => bcadd((string) $item->price, '0', 2),
            'voided_at' => null,
        ];

        $item->update([
            'voided_at' => now(),
            'void_reason' => $reason,
            'voided_by' => $actor->id,
        ]);

        $this->auditLogger->record(
            $actor,
            $item,
            AuditAction::INVOICE_ITEM_VOIDED,
            $old,
            [
                'price' => bcadd((string) $item->price, '0', 2),
                'voided_at' => $item->voided_at?->toISOString(),
                'void_reason' => $reason,
            ],
        );

        $this->recomputeInvoiceTotal->handle($invoice);

        return $item;
    }

    private function assertItemOnInvoice(Invoice $invoice, InvoiceItem $item): void
    {
        if ((string) $item->invoice_id !== (string) $invoice->id) {
            throw new InvalidArgumentException('Invoice item does not belong to this invoice.');
        }
    }
}

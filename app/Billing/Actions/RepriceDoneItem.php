<?php

namespace App\Billing\Actions;

use App\Billing\Audit\BillingAuditLogger;
use App\Billing\Domain\Exceptions\CannotRepriceDoneItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Enums\AuditAction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use InvalidArgumentException;

/**
 * Audited price change for a done line. Planned and in-progress prices stay on the item update path.
 */
final class RepriceDoneItem
{
    public function __construct(
        private readonly InvoiceItemFactsReader $factsReader,
        private readonly InvoiceItemStateResolver $stateResolver,
        private readonly BillingAuditLogger $auditLogger,
        private readonly RecomputeInvoiceTotal $recomputeInvoiceTotal,
    ) {}

    public function handle(Invoice $invoice, InvoiceItem $item, User $actor, string $price, string $reason): InvoiceItem
    {
        $this->assertItemOnInvoice($invoice, $item);
        InvoiceClosedException::throwIf($invoice->status);

        $reason = trim($reason);
        if ($reason === '') {
            throw new CannotRepriceDoneItem('A reason is required to reprice a completed line.');
        }

        if (! is_numeric($price)) {
            throw new CannotRepriceDoneItem('Price must be zero or greater.');
        }

        $formatted = bcadd($price, '0', 2);
        if (bccomp($formatted, '0', 2) === -1) {
            throw new CannotRepriceDoneItem('Price must be zero or greater.');
        }

        $state = $this->stateResolver->resolve($this->factsReader->read($item));
        if ($state !== InvoiceItemState::DONE) {
            throw CannotRepriceDoneItem::notDone();
        }

        $oldPrice = bcadd((string) $item->price, '0', 2);
        $item->update(['price' => $formatted]);

        $this->auditLogger->record(
            $actor,
            $item,
            AuditAction::INVOICE_ITEM_DONE_REPRICED,
            ['price' => $oldPrice],
            ['price' => $formatted, 'reason' => $reason],
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

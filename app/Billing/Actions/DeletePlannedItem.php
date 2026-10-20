<?php

namespace App\Billing\Actions;

use App\Billing\Audit\BillingAuditLogger;
use App\Billing\Domain\Exceptions\CannotDeleteNonPlannedItem;
use App\Billing\Domain\Exceptions\CannotMutateDoneItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Enums\AuditAction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use InvalidArgumentException;

final class DeletePlannedItem
{
    public function __construct(
        private readonly InvoiceItemFactsReader $factsReader,
        private readonly InvoiceItemStateResolver $stateResolver,
        private readonly BillingAuditLogger $auditLogger,
        private readonly RecomputeInvoiceTotal $recomputeInvoiceTotal,
    ) {}

    public function handle(Invoice $invoice, InvoiceItem $item, User $actor): void
    {
        $this->assertItemOnInvoice($invoice, $item);
        InvoiceClosedException::throwIf($invoice->status);

        $state = $this->stateResolver->resolve($this->factsReader->read($item));

        if ($state === InvoiceItemState::DONE) {
            throw CannotMutateDoneItem::locked();
        }

        if ($state !== InvoiceItemState::PLANNED) {
            throw CannotDeleteNonPlannedItem::refused();
        }

        $old = $this->moneySnapshot($item);
        $item->delete();

        $this->auditLogger->record(
            $actor,
            $item,
            AuditAction::INVOICE_ITEM_PLANNED_DELETED,
            $old,
            ['deleted_at' => $item->deleted_at?->toISOString()],
        );

        $this->recomputeInvoiceTotal->handle($invoice);
    }

    private function assertItemOnInvoice(Invoice $invoice, InvoiceItem $item): void
    {
        if ((string) $item->invoice_id !== (string) $invoice->id) {
            throw new InvalidArgumentException('Invoice item does not belong to this invoice.');
        }
    }

    /**
     * @return array{price: string, quantity: int, description: string|null}
     */
    private function moneySnapshot(InvoiceItem $item): array
    {
        return [
            'price' => bcadd((string) $item->price, '0', 2),
            'quantity' => (int) $item->quantity,
            'description' => $item->description,
        ];
    }
}

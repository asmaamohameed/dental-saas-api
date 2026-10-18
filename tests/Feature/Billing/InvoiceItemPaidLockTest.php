<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Tests\TestCase;

class InvoiceItemPaidLockTest extends TestCase
{
    public function test_owner_can_add_an_item_to_a_paid_zero_fee_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->paid()->create(['total_amount' => 0]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'price' => 100,
            'description' => 'Manual billing line item',
            'quantity' => 1,
        ])->assertCreated();

        $fresh = $invoice->fresh();
        $this->assertSame('100.00', (string) $fresh->total_amount);
        $this->assertSame(InvoiceStatus::UNPAID, $fresh->status);
    }

    public function test_owner_cannot_add_an_item_to_a_cancelled_or_refunded_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        foreach ([InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED] as $status) {
            $invoice = Invoice::factory()->create(['status' => $status, 'total_amount' => 0]);

            $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
                'price' => 100,
                'description' => 'Manual billing line item',
                'quantity' => 1,
            ])->assertForbidden();
        }
    }

    public function test_owner_can_still_view_items_of_a_paid_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->paid()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")
            ->assertOk();
    }
}

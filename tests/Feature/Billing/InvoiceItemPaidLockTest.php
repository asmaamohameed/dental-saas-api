<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Tests\TestCase;

class InvoiceItemPaidLockTest extends TestCase
{
    public function test_owner_can_add_a_planned_item_to_a_paid_invoice_and_status_becomes_partial(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = $this->paidInvoiceWithLine(total: 80, paid: 80);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'price' => 20,
            'description' => 'Extra manual charge',
            'quantity' => 1,
        ])->assertCreated();

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::PARTIAL, $fresh->status);
        $this->assertEquals(100, (float) $fresh->total_amount);
    }

    public function test_owner_can_edit_a_planned_item_on_a_paid_invoice_when_total_stays_at_or_above_settled(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = $this->paidInvoiceWithLine(total: 80, paid: 80);
        $extra = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned add-on',
            'price' => 30,
            'quantity' => 1,
        ]);
        $invoice->update(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 110]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$extra->id}", [
            'price' => 25,
            'quantity' => 1,
        ])->assertOk();

        $this->assertSame('25.00', (string) $extra->fresh()->price);
    }

    public function test_owner_can_delete_a_planned_item_on_a_paid_invoice_when_total_stays_at_or_above_settled(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = $this->paidInvoiceWithLine(total: 80, paid: 80);
        $extra = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Removable add-on',
            'price' => 40,
            'quantity' => 1,
        ]);
        $invoice->update(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 120]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$extra->id}")->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $extra->id]);
        $this->assertEquals(80, (float) $invoice->fresh()->total_amount);
    }

    public function test_add_item_to_cancelled_or_refunded_invoice_is_still_forbidden(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        foreach ([InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED] as $status) {
            $invoice = Invoice::factory()->create(['status' => $status]);
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

            $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
                'price' => 10,
                'description' => 'Should fail',
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

    private function paidInvoiceWithLine(float $total, float $paid): Invoice
    {
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::PAID,
            'total_amount' => $total,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Paid line item',
            'price' => $total,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => $paid]);

        return $invoice;
    }
}

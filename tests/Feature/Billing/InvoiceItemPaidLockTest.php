<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Tests\TestCase;

class InvoiceItemPaidLockTest extends TestCase
{
    public function test_owner_cannot_add_an_item_to_a_paid_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->paid()->create();
        $service = Service::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
        ])->assertForbidden();
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

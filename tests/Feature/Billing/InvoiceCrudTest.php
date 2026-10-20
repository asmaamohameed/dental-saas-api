<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\TestCase;

class InvoiceCrudTest extends TestCase
{
    public function test_receptionist_cannot_delete_an_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertForbidden();
    }

    public function test_owner_can_delete_an_invoice_with_no_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk();
    }

    public function test_owner_cannot_delete_an_invoice_that_has_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 200]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }
}

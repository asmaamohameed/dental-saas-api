<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    public function test_multiple_partial_payments_update_remaining_balance_and_status(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['total_amount' => 300]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status->value);
        $this->assertEquals(200, $invoice->remaining_amount);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 200,
            'method' => 'card',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertEquals(0, $invoice->remaining_amount);
    }

    public function test_payment_exceeding_remaining_balance_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 150,
            'method' => 'cash',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_doctor_cannot_create_a_payment(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_payment_update_and_delete_routes_are_gone(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->paid()->create(['total_amount' => 100]);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}", [
            'amount' => 80,
        ])->assertStatus(405);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 100]);
    }
}

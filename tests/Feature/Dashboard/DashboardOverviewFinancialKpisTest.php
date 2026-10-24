<?php

namespace Tests\Feature\Dashboard;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use Tests\TestCase;

class DashboardOverviewFinancialKpisTest extends TestCase
{
    public function test_total_invoiced_sums_active_invoices_excluding_cancelled_and_refunded_status(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        Invoice::factory()->create(['total_amount' => 250, 'status' => InvoiceStatus::PAID]);
        Invoice::factory()->create(['total_amount' => 999, 'status' => InvoiceStatus::CANCELLED]);
        Invoice::factory()->create(['total_amount' => 888, 'status' => InvoiceStatus::REFUNDED]);

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $this->assertEquals(350.0, $response->json('data.total_invoiced_all_time'));
    }

    public function test_outstanding_balance_is_remaining_after_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 75]);

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $this->assertEquals(125.0, $response->json('data.outstanding_balance_all_time'));
    }

    public function test_refund_increases_outstanding_balance(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PAID,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 200]);
        Refund::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 50,
        ]);

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $this->assertEquals(200.0, $response->json('data.total_invoiced_all_time'));
        $this->assertEquals(50.0, $response->json('data.outstanding_balance_all_time'));
    }
}

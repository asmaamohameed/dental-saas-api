<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class InvoiceCrudTest extends TestCase
{
    public function test_invoice_total_uses_service_default_price_ignoring_submitted_price(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $service = Service::factory()->create(['default_price' => 200]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $service->id, 'price' => 999, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('invoices', ['patient_id' => $patient->id, 'total_amount' => 400]);
    }

    public function test_invoice_with_other_service_uses_the_submitted_price(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $otherService = Service::factory()->other()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $otherService->id, 'price' => 150, 'quantity' => 1, 'description' => 'Custom procedure'],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('invoices', ['patient_id' => $patient->id, 'total_amount' => 150]);
    }

    public function test_doctor_cannot_create_an_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $service->id, 'price' => 100, 'quantity' => 1],
            ],
        ])->assertCreated();
    }

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

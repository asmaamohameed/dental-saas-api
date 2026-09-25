<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class AssistantAuthorizationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_assistant_can_list_patients(): void
    {
        $assistant = $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson('/api/v1/patients')
            ->assertOk();
    }

    public function test_assistant_can_view_a_patient(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson("/api/v1/patients/{$patient->id}")
            ->assertOk();
    }

    public function test_assistant_can_list_appointments(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        Appointment::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson('/api/v1/appointments')
            ->assertOk();
    }

    public function test_assistant_can_view_an_appointment(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $appointment = Appointment::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk();
    }

    public function test_assistant_cannot_list_invoice_items(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $invoice = Invoice::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")
            ->assertForbidden();
    }

    public function test_assistant_cannot_list_payments_on_an_invoice(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $invoice = Invoice::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertForbidden();
    }
}

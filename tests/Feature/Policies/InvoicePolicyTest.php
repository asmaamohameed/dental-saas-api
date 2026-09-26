<?php

namespace Tests\Feature\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
    }

    private function invoiceWithStatus(InvoiceStatus $status): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => $status,
        ]);
    }

    private function validItemsPayload(): array
    {
        $service = Service::factory()->create(['tenant_id' => $this->tenant->id, 'is_other' => false]);

        return [
            'items' => [
                [
                    'service_id' => $service->id,
                    'price' => 100,
                    'quantity' => 1,
                ],
            ],
        ];
    }

    public function test_owner_cannot_update_items_on_a_partial_invoice_either(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->putJson("/api/v1/invoices/{$invoice->id}", $this->validItemsPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_receptionist_cannot_update_items_on_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->putJson("/api/v1/invoices/{$invoice->id}", $this->validItemsPayload())
            ->assertForbidden();
    }

    public function test_receptionist_can_update_items_on_an_unpaid_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->putJson("/api/v1/invoices/{$invoice->id}", $this->validItemsPayload())
            ->assertOk();
    }

    public function test_doctor_cannot_reach_the_update_invoice_route_at_all(): void
    {
        // Blocked one layer up by EnsureUserRole middleware (OWNER, RECEPTIONIST only).
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->putJson("/api/v1/invoices/{$invoice->id}", $this->validItemsPayload())
            ->assertOk();
    }

    public function test_update_items_ability_now_explicitly_denies_doctor_independent_of_middleware(): void
    {
        $doctor = $this->userWithRole(UserRole::DOCTOR);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->assertFalse(Gate::forUser($doctor)->allows('updateItems', $invoice));
    }

    public function test_update_items_ability_still_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = $this->userWithRole(UserRole::RECEPTIONIST);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->assertFalse(Gate::forUser($receptionist)->allows('updateItems', $invoice));
    }

    public function test_update_items_ability_still_allows_owner_regardless_of_status(): void
    {
        $owner = $this->userWithRole(UserRole::OWNER);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->assertTrue(Gate::forUser($owner)->allows('updateItems', $invoice));
    }
}

<?php

namespace Tests\Feature\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
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

    private function invoice(float $total = 200): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => InvoiceStatus::UNPAID,
            'total_amount' => $total,
        ]);
    }

    // --- create ---

    public function test_receptionist_can_record_a_payment(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_owner_can_record_a_payment(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_doctor_cannot_record_a_payment(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 50,
            'method' => 'cash',
        ])->assertForbidden();
    }

    // --- viewAny / view + tenant isolation ---

    public function test_doctor_can_view_payments_for_an_invoice_in_their_tenant(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoice();

        $this->getJson("/api/v1/invoices/{$invoice->id}/payments")->assertOk();
    }

    public function test_user_from_another_tenant_cannot_view_or_reach_a_payment(): void
    {
        $invoice = $this->invoice();
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'received_by' => $this->userWithRole(UserRole::RECEPTIONIST)->id,
        ]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($outsider, ['*']);

        $this->getJson("/api/v1/invoices/{$invoice->id}/payments")->assertNotFound();
        $this->getJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}")->assertNotFound();
    }

    // --- update / delete: owner-only, enforced today by route middleware ---

    public function test_owner_can_update_a_payment(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        $invoice = $this->invoice();
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'received_by' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}", [
            'amount' => 75,
        ])->assertOk();
    }

    public function test_receptionist_cannot_reach_the_update_payment_route(): void
    {
        // Blocked by EnsureUserRole::using(OWNER) middleware before the
        // PaymentPolicy is even consulted.
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        $invoice = $this->invoice();
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'received_by' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}", [
            'amount' => 75,
        ])->assertForbidden();
    }

    /**
     * Defense-in-depth check, bypassing route middleware entirely: even if a
     * non-owner somehow reached PaymentPolicy::update()/delete() directly,
     * the policy itself must still deny them (it does — both hard-return false).
     */
    public function test_policy_itself_denies_update_and_delete_for_non_owners_independent_of_middleware(): void
    {
        $receptionist = $this->userWithRole(UserRole::RECEPTIONIST);
        $doctor = $this->userWithRole(UserRole::DOCTOR);
        $invoice = $this->invoice();
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'received_by' => $receptionist->id,
        ]);

        $this->assertFalse(Gate::forUser($receptionist)->allows('update', $payment));
        $this->assertFalse(Gate::forUser($receptionist)->allows('delete', $payment));
        $this->assertFalse(Gate::forUser($doctor)->allows('update', $payment));
        $this->assertFalse(Gate::forUser($doctor)->allows('delete', $payment));
    }

    public function test_owner_can_delete_a_payment(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        $invoice = $this->invoice();
        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'received_by' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$payment->id}")->assertOk();
    }
}

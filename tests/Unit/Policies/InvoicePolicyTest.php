<?php

namespace Tests\Unit\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class InvoicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePolicy $policy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new InvoicePolicy;
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->make(['role' => $role]);
    }

    private function invoice(InvoiceStatus $status): Invoice
    {
        return new Invoice(['status' => $status]);
    }

    public function test_before_grants_owner_every_ability(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'updateItems'));
    }

    public function test_update_items_allows_doctor_on_unpaid_invoice(): void
    {
        $doctor = $this->user(UserRole::DOCTOR);

        $this->assertTrue($this->policy->updateItems($doctor, $this->invoice(InvoiceStatus::UNPAID)));
    }

    public function test_update_items_allows_receptionist_on_unpaid_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST);

        $this->assertTrue($this->policy->updateItems($receptionist, $this->invoice(InvoiceStatus::UNPAID)));
    }

    public function test_update_items_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST);

        $this->assertFalse($this->policy->updateItems($receptionist, $this->invoice(InvoiceStatus::PARTIAL)));
    }

    public function test_update_items_allows_owner_on_partial_invoice_via_direct_call(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $this->assertTrue($this->policy->updateItems($owner, $this->invoice(InvoiceStatus::PARTIAL)));
    }

    private function persistedInvoice(InvoiceStatus $status): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => $status,
        ]);
    }

    public function test_update_items_ability_now_explicitly_denies_doctor_independent_of_middleware(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $invoice = $this->persistedInvoice(InvoiceStatus::PARTIAL);

        $this->assertFalse(Gate::forUser($doctor)->allows('updateItems', $invoice));
    }

    public function test_update_items_ability_still_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $invoice = $this->persistedInvoice(InvoiceStatus::PARTIAL);

        $this->assertFalse(Gate::forUser($receptionist)->allows('updateItems', $invoice));
    }

    public function test_update_items_ability_still_allows_owner_regardless_of_status(): void
    {
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        $invoice = $this->persistedInvoice(InvoiceStatus::PARTIAL);

        $this->assertTrue(Gate::forUser($owner)->allows('updateItems', $invoice));
    }
}

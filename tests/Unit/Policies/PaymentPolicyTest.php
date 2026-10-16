<?php

namespace Tests\Unit\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private PaymentPolicy $policy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PaymentPolicy;
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a'): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);
    }

    private function invoice(string $tenantId = 'tenant-a'): Invoice
    {
        $invoice = new Invoice;
        $invoice->tenant_id = $tenantId;

        return $invoice;
    }

    public function test_before_grants_owner_every_ability(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'delete'));
    }

    public function test_create_is_receptionist_only_same_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->create($receptionist, $this->invoice('tenant-a')));
        $this->assertFalse($this->policy->create($receptionist, $this->invoice('tenant-b')));
        $this->assertTrue($this->policy->create($doctor, $this->invoice('tenant-a')));
        $this->assertTrue($this->policy->create($this->user(UserRole::ASSISTANT, 'tenant-a'), $this->invoice('tenant-a')));
    }

    public function test_update_is_always_false_for_non_owner_relying_on_before(): void
    {
        $doctor = $this->user(UserRole::DOCTOR);
        $payment = new Payment(['tenant_id' => 'tenant-a']);

        $this->assertFalse($this->policy->update($doctor, $payment));
    }

    public function test_delete_is_always_false_for_non_owner_relying_on_before(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST);
        $payment = new Payment(['tenant_id' => 'tenant-a']);

        $this->assertFalse($this->policy->delete($receptionist, $payment));
    }

    public function test_view_any_denies_assistant(): void
    {
        $assistant = $this->user(UserRole::ASSISTANT, 'tenant-a');

        $this->assertFalse($this->policy->viewAny($assistant, $this->invoice('tenant-a')));
    }

    public function test_policy_itself_denies_update_and_delete_for_non_owners_independent_of_middleware(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => InvoiceStatus::UNPAID,
            'total_amount' => 200,
        ]);
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
}

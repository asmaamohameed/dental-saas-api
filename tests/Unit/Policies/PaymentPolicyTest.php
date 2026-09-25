<?php

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    private PaymentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PaymentPolicy;
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
        $this->assertFalse($this->policy->create($doctor, $this->invoice('tenant-a')));
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
}

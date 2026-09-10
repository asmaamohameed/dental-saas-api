<?php

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Policies\InventoryTransactionPolicy;
use Tests\TestCase;

class InventoryTransactionPolicyTest extends TestCase
{
    private InventoryTransactionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new InventoryTransactionPolicy;
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a'): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);
    }

    private function item(string $tenantId = 'tenant-a'): InventoryItem
    {
        return InventoryItem::factory()->make(['tenant_id' => $tenantId]);
    }

    public function test_before_grants_owner_bypass_for_non_delete_abilities(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'create'));
    }

    public function test_before_never_bypasses_delete_even_for_owner(): void
    {
        $this->assertNull($this->policy->before($this->user(UserRole::OWNER), 'delete'));
    }

    public function test_view_any_denies_cross_tenant_access(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->viewAny($doctor, $this->item('tenant-a')));
        $this->assertFalse($this->policy->viewAny($doctor, $this->item('tenant-b')));
    }

    public function test_view_denies_cross_tenant_access(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');
        $transaction = new InventoryTransaction(['tenant_id' => 'tenant-b']);

        $this->assertFalse($this->policy->view($doctor, $transaction));
    }

    public function test_create_requires_doctor_or_receptionist_and_same_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertTrue($this->policy->create($receptionist, $this->item('tenant-a')));
        $this->assertFalse($this->policy->create($receptionist, $this->item('tenant-b')));
    }
}

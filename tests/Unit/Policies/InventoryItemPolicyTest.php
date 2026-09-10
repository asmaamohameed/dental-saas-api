<?php

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\User;
use App\Policies\InventoryItemPolicy;
use Tests\TestCase;

class InventoryItemPolicyTest extends TestCase
{
    private InventoryItemPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new InventoryItemPolicy;
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a'): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);
    }

    private function item(string $tenantId = 'tenant-a'): InventoryItem
    {
        return InventoryItem::factory()->make(['tenant_id' => $tenantId]);
    }

    public function test_before_grants_owner_every_ability(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'delete'));
    }

    public function test_view_any_allows_doctor_and_receptionist_only(): void
    {
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::DOCTOR)));
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::RECEPTIONIST)));
    }

    public function test_view_denies_cross_tenant_access(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->view($doctor, $this->item('tenant-a')));
        $this->assertFalse($this->policy->view($doctor, $this->item('tenant-b')));
    }

    public function test_create_update_delete_restore_are_owner_only_via_before(): void
    {
        $doctor = $this->user(UserRole::DOCTOR);
        $item = $this->item();

        $this->assertFalse($this->policy->create($doctor));
        $this->assertFalse($this->policy->update($doctor, $item));
        $this->assertFalse($this->policy->delete($doctor, $item));
        $this->assertFalse($this->policy->restore($doctor, $item));
    }
}

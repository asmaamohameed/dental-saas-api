<?php

namespace Tests\Unit\Support\Tenancy;

use App\Support\Tenancy\CurrentTenant;
use PHPUnit\Framework\TestCase;

class CurrentTenantTest extends TestCase
{
    public function test_id_is_null_and_check_is_false_before_anything_is_set(): void
    {
        $currentTenant = new CurrentTenant;

        $this->assertNull($currentTenant->id());
        $this->assertFalse($currentTenant->check());
    }

    public function test_set_stores_the_tenant_id_and_check_becomes_true(): void
    {
        $currentTenant = new CurrentTenant;
        $currentTenant->set('tenant-uuid-123');

        $this->assertSame('tenant-uuid-123', $currentTenant->id());
        $this->assertTrue($currentTenant->check());
    }

    public function test_set_can_reset_the_tenant_id_back_to_null(): void
    {
        $currentTenant = new CurrentTenant;
        $currentTenant->set('tenant-uuid-123');
        $currentTenant->set(null);

        $this->assertNull($currentTenant->id());
        $this->assertFalse($currentTenant->check());
    }
}

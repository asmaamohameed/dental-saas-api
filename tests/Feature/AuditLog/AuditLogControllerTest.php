<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $this->tenant = $tenant;
    }

    public function test_owner_can_list_audit_logs(): void
    {
        $owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);
        AuditLog::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    public function test_non_owner_cannot_list_audit_logs(): void
    {
        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($doctor, ['*']);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}

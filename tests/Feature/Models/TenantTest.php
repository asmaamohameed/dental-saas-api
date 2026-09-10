<?php

namespace Tests\Feature\Models;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\ToothRecord;
use App\Models\User;
use App\Models\XrayAttachment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_has_many_subscriptions(): void
    {
        $tenant = Tenant::factory()->create();
        Subscription::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertCount(1, $tenant->subscriptions);
    }

    public function test_tenant_has_many_users(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertCount(1, $tenant->users);
    }

    public function test_tenant_has_many_patients(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        Patient::factory()->create();

        $this->assertCount(1, $tenant->patients);
    }

    public function test_tenant_has_many_appointments(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        Appointment::factory()->create();

        $this->assertCount(1, $tenant->appointments);
    }

    public function test_tenant_has_many_services(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        Service::factory()->create();

        $this->assertCount(1, $tenant->services);
    }

    public function test_tenant_has_many_invoices(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        Invoice::factory()->create();

        $this->assertCount(1, $tenant->invoices);
    }

    public function test_tenant_has_many_xray_attachments(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        XrayAttachment::factory()->create();

        $this->assertCount(1, $tenant->xrayAttachments);
    }

    public function test_tenant_has_many_tooth_records(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        ToothRecord::factory()->create();

        $this->assertCount(1, $tenant->toothRecords);
    }

    public function test_tenant_has_many_audit_logs(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        AuditLog::factory()->create();

        $this->assertCount(1, $tenant->auditLogs);
    }
}

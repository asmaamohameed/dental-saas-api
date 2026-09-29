<?php

namespace Tests\Feature\Admin;

use App\Enums\TenantStatus;
use App\Models\AdminAuditLog;
use App\Models\ClinicMember;
use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicAdministrationTest extends TestCase
{
    use PlatformAdminHelpers;

    public function test_admin_can_suspend_and_reactivate_a_clinic_without_deleting_memberships(): void
    {
        $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create();
        $owner = $this->makeClinicUser($clinic, 'owner');

        $this->postJson('/admin/clinics/'.$clinic->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.is_suspended', true);

        $this->postJson('/admin/clinics/'.$clinic->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('message', 'Clinic is already suspended.');

        $clinic->refresh();
        $this->assertSame(TenantStatus::INACTIVE, $clinic->status);
        $this->assertTrue(
            ClinicMember::query()->where('user_id', $owner->id)->where('clinic_id', $clinic->id)->value('is_active')
        );
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'clinic.suspended')->count());

        Sanctum::actingAs($owner, ['*']);
        $this->getJson('/api/v1/auth/me')->assertForbidden();

        $this->actingAsPlatformAdmin();
        $this->postJson('/admin/clinics/'.$clinic->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, AdminAuditLog::query()->where('action', 'clinic.activated')->count());
    }

    public function test_admin_can_filter_clinics_and_update_safe_settings(): void
    {
        $this->actingAsPlatformAdmin();
        $empty = Tenant::factory()->create(['name' => 'Empty Clinic', 'subdomain' => 'empty-clinic']);
        $staffed = Tenant::factory()->create(['name' => 'Empty Clinic', 'subdomain' => 'staffed-clinic']);
        $this->makeClinicUser($staffed, 'owner');
        $this->makeClinicUser($staffed, 'doctor');

        $this->getJson('/admin/clinics?issue=no_members')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $empty->id);

        $this->getJson('/admin/clinics?search='.urlencode("%' OR 1=1 --"))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $this->patchJson('/admin/clinics/'.$empty->id, [
            'name' => 'Renamed Clinic',
            'locale' => 'en',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed Clinic')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'clinic.updated',
            'target_id' => $empty->id,
        ]);
    }

    public function test_dashboard_counts_clinics_missing_owners_or_doctors(): void
    {
        $this->actingAsPlatformAdmin();
        $noOwner = Tenant::factory()->create();
        $this->makeClinicUser($noOwner, 'doctor');

        $noDoctor = Tenant::factory()->create();
        $this->makeClinicUser($noDoctor, 'owner');

        $suspended = Tenant::factory()->inactive()->create();
        $this->makeClinicUser($suspended, 'owner');

        $this->getJson('/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinics.total', 3)
            ->assertJsonPath('data.clinics.suspended', 1)
            ->assertJsonPath('data.clinics.no_active_owner', 1)
            ->assertJsonPath('data.clinics.no_doctors', 2);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\ClinicMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class ClinicProvisioningTest extends TestCase
{
    use PlatformAdminHelpers;

    public function test_admin_can_create_a_clinic_and_its_owner(): void
    {
        $this->actingAsPlatformAdmin();

        $created = $this->postJson('/admin/clinics', [
            'name' => 'North Clinic',
            'subdomain' => 'north-clinic',
            'locale' => 'en',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'North Clinic')
            ->assertJsonPath('data.subdomain', 'north-clinic')
            ->assertJsonPath('data.status', 'active');

        $clinicId = $created->json('data.id');

        $this->postJson('/admin/clinics/'.$clinicId.'/members', [
            'name' => 'North Owner',
            'email' => 'owner@north.test',
            'password' => 'owner-pass-1',
            'role' => 'owner',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'owner@north.test')
            ->assertJsonPath('data.roles.0', 'owner')
            ->assertJsonPath('data.clinic.id', $clinicId);

        $owner = User::query()->withoutGlobalScope('tenant')->where('email', 'owner@north.test')->firstOrFail();
        $this->assertSame($clinicId, $owner->tenant_id);
        $this->assertSame(UserRole::OWNER, $owner->role);
        $this->assertNull(app(CurrentTenant::class)->id());

        $audit = AdminAuditLog::query()->where('action', 'user.created')->firstOrFail();
        $this->assertStringNotContainsString('owner-pass-1', json_encode($audit->after_values));

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@north.test',
            'password' => 'owner-pass-1',
        ])->assertOk()->assertJsonPath('data.user.id', $owner->id);
    }

    public function test_admin_can_add_a_role_without_removing_the_roles_the_user_already_has(): void
    {
        $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $doctor = $this->makeClinicUser($clinic, 'doctor');
        $this->makeClinicUser($other, 'owner');

        $this->postJson('/admin/clinics/'.$clinic->id.'/members', [
            'user_id' => $doctor->id,
            'role' => 'owner',
        ])->assertCreated();

        $membership = ClinicMember::query()->where('user_id', $doctor->id)->where('clinic_id', $clinic->id)->firstOrFail();
        $roles = $membership->roles()->pluck('name')->sort()->values()->all();

        $this->assertSame(['doctor', 'owner'], $roles);
        $this->assertSame(UserRole::DOCTOR, User::query()->withoutGlobalScope('tenant')->findOrFail($doctor->id)->role);
        $this->assertSame(0, ClinicMember::query()->where('user_id', $doctor->id)->where('clinic_id', $other->id)->count());

        $this->postJson('/admin/clinics/'.$clinic->id.'/members', [
            'user_id' => $doctor->id,
            'role' => 'owner',
        ])->assertStatus(422);

        $this->postJson('/admin/clinics/'.$clinic->id.'/members', [
            'name' => 'North Owner',
            'email' => $doctor->email,
            'password' => 'owner-pass-1',
            'role' => 'owner',
        ])->assertStatus(422);
    }

    public function test_clinic_staff_cannot_create_clinics_or_assign_roles(): void
    {
        $clinic = Tenant::factory()->create();
        $this->actingAsTenantUser($clinic, UserRole::OWNER);

        $this->postJson('/admin/clinics', [
            'name' => 'Blocked Clinic',
            'subdomain' => 'blocked-clinic',
        ])->assertForbidden();

        $this->postJson('/admin/clinics/'.$clinic->id.'/members', [
            'name' => 'Blocked Owner',
            'email' => 'blocked@north.test',
            'password' => 'owner-pass-1',
            'role' => 'owner',
        ])->assertForbidden();
    }
}

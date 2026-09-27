<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipAdministrationTest extends TestCase
{
    use PlatformAdminHelpers;

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create();
        $owner = $this->makeClinicUser($clinic, 'owner');
        $membership = ClinicMember::query()->where('user_id', $owner->id)->where('clinic_id', $clinic->id)->firstOrFail();

        $this->postJson('/admin/memberships/'.$membership->id.'/revoke')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This clinic must keep at least one active owner. Assign another owner first.');

        $this->patchJson('/admin/memberships/'.$membership->id, ['role' => 'doctor'])
            ->assertStatus(422);

        $this->postJson('/admin/users/'.$owner->id.'/deactivate')
            ->assertStatus(422);

        $this->assertTrue($membership->fresh()->is_active);
        $this->assertTrue(User::query()->withoutGlobalScope('tenant')->findOrFail($owner->id)->is_active);
        $this->assertSame(0, AdminAuditLog::query()->where('action', 'membership.revoked')->count());
    }

    public function test_a_replacement_owner_allows_the_previous_owner_to_be_revoked(): void
    {
        $admin = $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create();
        $owner = $this->makeClinicUser($clinic, 'owner');
        $doctor = $this->makeClinicUser($clinic, 'doctor');
        $doctorMembership = ClinicMember::query()->where('user_id', $doctor->id)->firstOrFail();

        $this->patchJson('/admin/memberships/'.$doctorMembership->id, [
            'role' => 'owner',
            'clinic_id' => Tenant::factory()->create()->id,
        ])->assertOk()->assertJsonPath('data.roles.0', 'owner');

        $doctorMembership->refresh();
        $this->assertSame($clinic->id, $doctorMembership->clinic_id);

        $ownerMembership = ClinicMember::query()->where('user_id', $owner->id)->firstOrFail();
        $this->postJson('/admin/memberships/'.$ownerMembership->id.'/revoke')->assertOk();

        $this->assertFalse($ownerMembership->fresh()->is_active);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'membership.revoked',
            'admin_user_id' => $admin->id,
            'tenant_id' => $clinic->id,
        ]);
    }

    public function test_role_changes_stay_inside_one_clinic_for_a_user_with_two_memberships(): void
    {
        $this->actingAsPlatformAdmin();
        $clinicA = Tenant::factory()->create();
        $clinicB = Tenant::factory()->create();
        $user = $this->makeClinicUser($clinicA, 'owner');

        $this->postJson('/admin/memberships', [
            'user_id' => $user->id,
            'clinic_id' => $clinicB->id,
            'role' => 'doctor',
        ])->assertCreated();

        $second = ClinicMember::query()->where('user_id', $user->id)->where('clinic_id', $clinicB->id)->firstOrFail();

        $this->patchJson('/admin/memberships/'.$second->id, ['role' => 'assistant'])->assertOk();

        $user = User::query()->withoutGlobalScope('tenant')->findOrFail($user->id);
        $this->assertSame(UserRole::OWNER, $user->role);
        $this->assertSame($clinicA->id, $user->tenant_id);
        $this->assertTrue($user->hasRoleIn(Clinic::query()->findOrFail($clinicA->id), 'owner'));
        $this->assertTrue($user->hasRoleIn(Clinic::query()->findOrFail($clinicB->id), 'assistant'));
        $this->assertFalse($user->hasRoleIn(Clinic::query()->findOrFail($clinicA->id), 'assistant'));
    }

    public function test_invalid_roles_and_duplicate_memberships_are_rejected(): void
    {
        $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create();
        $owner = $this->makeClinicUser($clinic, 'owner');

        $this->postJson('/admin/memberships', [
            'user_id' => $owner->id,
            'clinic_id' => $clinic->id,
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->postJson('/admin/memberships', [
            'user_id' => $owner->id,
            'clinic_id' => $clinic->id,
            'role' => 'owner',
        ])->assertStatus(422);

        $this->assertSame(1, ClinicMember::query()->where('user_id', $owner->id)->count());
    }

    public function test_revoked_membership_blocks_clinic_access_and_can_be_restored(): void
    {
        $clinic = Tenant::factory()->create();
        $owner = $this->makeClinicUser($clinic, 'owner');
        $doctor = $this->makeClinicUser($clinic, 'doctor');
        $membership = ClinicMember::query()->where('user_id', $doctor->id)->firstOrFail();

        Sanctum::actingAs($doctor, ['*']);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->actingAsPlatformAdmin();
        $this->postJson('/admin/memberships/'.$membership->id.'/revoke')->assertOk();

        Sanctum::actingAs($doctor, ['*']);
        $this->getJson('/api/v1/auth/me')->assertForbidden();

        $this->actingAsPlatformAdmin();
        $this->postJson('/admin/memberships/'.$membership->id.'/restore')->assertOk();

        Sanctum::actingAs($doctor, ['*']);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertTrue(Role::query()->where('name', 'doctor')->exists());
        $this->assertNotNull($owner->id);
    }

    public function test_deactivating_a_user_revokes_sessions_without_touching_another_clinic(): void
    {
        $clinicA = Tenant::factory()->create();
        $clinicB = Tenant::factory()->create();
        $this->makeClinicUser($clinicA, 'owner');
        $doctor = $this->makeClinicUser($clinicA, 'doctor');
        $other = $this->makeClinicUser($clinicB, 'owner');

        $admin = AdminUser::factory()->create([
            'password_hash' => Hash::make('password-password'),
        ]);
        $adminToken = $this->postJson('/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'password-password',
        ])->json('data.access_token');

        $doctorToken = $doctor->createToken('phone')->plainTextToken;
        $this->withToken($doctorToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($adminToken)
            ->postJson('/admin/users/'.$doctor->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->app['auth']->forgetGuards();

        $this->withToken($doctorToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $doctor->id,
        ]);

        $other = User::query()->withoutGlobalScope('tenant')->findOrFail($other->id);
        $this->assertTrue($other->is_active);
        $this->assertSame($clinicB->id, $other->tenant_id);
    }
}

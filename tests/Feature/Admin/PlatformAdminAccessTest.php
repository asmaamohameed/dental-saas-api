<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use PlatformAdminHelpers;

    public function test_admin_can_view_the_dashboard_and_clinic_directory(): void
    {
        $this->actingAsPlatformAdmin();
        $clinic = Tenant::factory()->create(['name' => 'North Clinic']);
        $this->makeClinicUser($clinic, 'owner');

        $this->getJson('/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinics.total', 1)
            ->assertJsonPath('data.users.total', 1);

        $this->getJson('/admin/clinics')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'North Clinic');
    }

    #[DataProvider('clinicRoles')]
    public function test_clinic_roles_cannot_access_admin_endpoints(string $role): void
    {
        $clinic = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $actor = $this->makeClinicUser($clinic, $role);
        $stranger = $this->makeClinicUser($other, 'doctor');
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/admin/dashboard')->assertForbidden();
        $this->getJson('/admin/clinics')->assertForbidden();
        $this->getJson('/admin/clinics/'.$other->id)->assertForbidden();
        $this->getJson('/admin/users')->assertForbidden();
        $this->getJson('/admin/users/'.$stranger->id)->assertForbidden();
        $this->getJson('/admin/memberships')->assertForbidden();
        $this->getJson('/admin/roles')->assertForbidden();
        $this->getJson('/admin/audit-logs')->assertForbidden();
        $this->postJson('/admin/clinics/'.$other->id.'/suspend')->assertForbidden();
        $this->postJson('/admin/users/'.$stranger->id.'/deactivate')->assertForbidden();

        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/admin/clinics')->assertUnauthorized();
        $this->getJson('/admin/audit-logs')->assertUnauthorized();
    }

    public function test_admin_queries_ignore_a_previously_selected_clinic(): void
    {
        $this->actingAsPlatformAdmin();
        $clinicA = Tenant::factory()->create();
        $clinicB = Tenant::factory()->create();
        $userA = $this->makeClinicUser($clinicA, 'owner');
        $userB = $this->makeClinicUser($clinicB, 'doctor');
        app(CurrentTenant::class)->set($clinicA->id);

        $response = $this->getJson('/admin/users')->assertOk();
        $emails = collect($response->json('data.items'))->pluck('email');

        $this->assertTrue($emails->contains($userA->email));
        $this->assertTrue($emails->contains($userB->email));
        $this->assertNull(app(CurrentTenant::class)->id());
    }

    public function test_invalid_and_missing_ids_do_not_reveal_other_clinics(): void
    {
        $this->actingAsPlatformAdmin();
        Tenant::factory()->create();

        $this->getJson('/admin/clinics/'.Str::uuid())->assertNotFound();
        $this->postJson('/admin/clinics/not-a-uuid/suspend')->assertNotFound();
        $this->getJson('/admin/users/'.Str::uuid())->assertNotFound();
        $this->patchJson('/admin/memberships/'.Str::uuid(), ['role' => 'doctor'])->assertNotFound();
    }

    public function test_roles_are_readable_and_not_editable(): void
    {
        $this->actingAsPlatformAdmin();

        $this->getJson('/admin/roles')
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.roles.0.name', 'owner');

        $this->getJson('/admin/roles/doctor')
            ->assertOk()
            ->assertJsonPath('data.role.permissions.staff:create', false)
            ->assertJsonPath('data.role.permissions.patient:view', true);

        $this->postJson('/admin/roles', ['name' => 'superuser'])->assertStatus(405);
        $this->putJson('/admin/roles/owner', ['permissions' => []])->assertStatus(405);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function clinicRoles(): array
    {
        return [
            'owner' => ['owner'],
            'doctor' => ['doctor'],
            'assistant' => ['assistant'],
            'receptionist' => ['receptionist'],
        ];
    }
}

<?php

namespace Tests\Feature\Clinic;

use App\Enums\ClinicTheme;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_a_clinic_theme(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/v1/clinic/theme', [
            'theme' => ClinicTheme::MintClinic->value,
        ])->assertOk()
            ->assertJsonPath('data.tenant.theme', ClinicTheme::MintClinic->value);

        $this->assertSame(ClinicTheme::MintClinic, $tenant->fresh()->theme);
    }

    public function test_theme_is_returned_for_every_staff_member_of_the_clinic(): void
    {
        $tenant = Tenant::factory()->create([
            'theme' => ClinicTheme::RoyalCare->value,
        ]);
        app(CurrentTenant::class)->set($tenant->id);

        $doctor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::DOCTOR,
        ]);

        Sanctum::actingAs($doctor, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.tenant.theme', ClinicTheme::RoyalCare->value);
    }

    public function test_non_owner_cannot_change_the_clinic_theme(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);

        Sanctum::actingAs($receptionist, ['*']);

        $this->patchJson('/api/v1/clinic/theme', [
            'theme' => ClinicTheme::OceanBreeze->value,
        ])->assertForbidden();

        $this->assertNull($tenant->fresh()->theme);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/v1/clinic/theme', [
            'theme' => 'neon-unknown',
        ])->assertUnprocessable();

        $this->assertNull($tenant->fresh()->theme);
    }

    public function test_one_clinic_theme_does_not_change_another_clinic(): void
    {
        $clinicA = Tenant::factory()->create();
        $clinicB = Tenant::factory()->create([
            'theme' => ClinicTheme::OceanBreeze->value,
        ]);

        app(CurrentTenant::class)->set($clinicA->id);

        $owner = User::factory()->create([
            'tenant_id' => $clinicA->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $this->patchJson('/api/v1/clinic/theme', [
            'theme' => ClinicTheme::WarmPeach->value,
        ])->assertOk();

        $this->assertSame(ClinicTheme::WarmPeach, $clinicA->fresh()->theme);
        $this->assertSame(ClinicTheme::OceanBreeze, $clinicB->fresh()->theme);
    }
}

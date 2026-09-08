<?php

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_doctor_receptionist_and_owner_can_view_services(): void
    {
        $svc = Service::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([UserRole::OWNER, UserRole::DOCTOR, UserRole::RECEPTIONIST] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/v1/services')->assertOk();
            $this->getJson("/api/v1/services/{$svc->id}")->assertOk();
        }
    }

    #[DataProvider('creatorRolesProvider')]
    public function test_owner_and_receptionist_can_create_service(UserRole $role): void
    {
        $this->actingAsRole($role);

        $this->postJson('/api/v1/services', [
            'name_ar' => 'خدمة جديدة',
            'default_price' => 100,
        ])->assertCreated();
    }

    public static function creatorRolesProvider(): array
    {
        return [
            'owner' => [UserRole::OWNER],
            'receptionist' => [UserRole::RECEPTIONIST],
        ];
    }

    public function test_doctor_cannot_create_service(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $this->postJson('/api/v1/services', [
            'name_ar' => 'خدمة جديدة',
            'default_price' => 100,
        ])->assertForbidden();
    }

    public function test_only_owner_can_delete_service(): void
    {
        $svc = Service::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAsRole(UserRole::RECEPTIONIST);
        $this->deleteJson("/api/v1/services/{$svc->id}")->assertForbidden();

        $this->actingAsRole(UserRole::OWNER);
        $this->deleteJson("/api/v1/services/{$svc->id}")->assertOk();
    }

    public function test_receptionist_cannot_change_default_price_but_can_update_other_fields(): void
    {
        $svc = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'default_price' => 100,
            'name_ar' => 'اسم قديم',
        ]);

        $this->actingAsRole(UserRole::RECEPTIONIST);

        $this->putJson("/api/v1/services/{$svc->id}", [
            'default_price' => 250,
        ])->assertStatus(422)->assertJsonValidationErrors(['default_price']);

        $this->putJson("/api/v1/services/{$svc->id}", [
            'name_ar' => 'اسم جديد',
        ])->assertOk()->assertJsonPath('data.name_ar', 'اسم جديد');

        $svc->refresh();
        $this->assertSame('100.00', $svc->default_price);
    }

    public function test_owner_can_change_default_price(): void
    {
        $svc = Service::factory()->create(['tenant_id' => $this->tenant->id, 'default_price' => 100]);

        $this->actingAsRole(UserRole::OWNER);

        $this->putJson("/api/v1/services/{$svc->id}", [
            'default_price' => 250,
        ])->assertOk()->assertJsonPath('data.default_price', 250);
    }

    public function test_deleting_other_service_returns_422_via_http(): void
    {
        $other = Service::factory()->other()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAsRole(UserRole::OWNER);

        $this->deleteJson("/api/v1/services/{$other->id}")->assertStatus(422);
    }

    public function test_toggle_active_on_other_service_returns_422_via_http(): void
    {
        $other = Service::factory()->other()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAsRole(UserRole::OWNER);

        $this->patchJson("/api/v1/services/{$other->id}/toggle-active")->assertStatus(422);
    }
}

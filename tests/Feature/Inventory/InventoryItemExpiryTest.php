<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Support\Inventory\InventoryExpiry;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Roles\ClinicPermissionGate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryItemExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        config([
            'clinic.timezone' => 'Africa/Cairo',
            'inventory.expiry_warning_days' => 30,
        ]);
    }

    private function clinicToday(): Carbon
    {
        return InventoryExpiry::today();
    }

    private function createItem(array $overrides = []): InventoryItem
    {
        $quantity = (string) ($overrides['current_quantity'] ?? '10.00');
        unset($overrides['current_quantity']);

        return InventoryItem::factory()
            ->withQuantity($quantity)
            ->create($overrides);
    }

    public function test_expiry_alert_includes_today_as_expiring_soon_not_expired(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $today = $this->createItem(['expiry_date' => $this->clinicToday()->toDateString()]);
        $yesterday = $this->createItem([
            'expiry_date' => $this->clinicToday()->copy()->subDay()->toDateString(),
        ]);

        $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);

        $response = $this->getJson('/api/v1/inventory-items?expiry=alert')->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($today->id));
        $this->assertTrue($ids->contains($yesterday->id));

        $todayPayload = collect($response->json('data.items'))->firstWhere('id', $today->id);
        $this->assertFalse($todayPayload['is_expired']);
        $this->assertTrue($todayPayload['is_expiring_soon']);
        $this->assertSame(0, $todayPayload['days_to_expiry']);

        $yesterdayPayload = collect($response->json('data.items'))->firstWhere('id', $yesterday->id);
        $this->assertTrue($yesterdayPayload['is_expired']);
        $this->assertFalse($yesterdayPayload['is_expiring_soon']);
        $this->assertSame(-1, $yesterdayPayload['days_to_expiry']);
    }

    public function test_warning_window_boundaries(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $inWindow = $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(30)->toDateString(),
        ]);
        $outsideWindow = $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(31)->toDateString(),
        ]);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $ids = collect($this->getJson('/api/v1/inventory-items?expiry=expiring')->json('data.items'))->pluck('id');

        $this->assertTrue($ids->contains($inWindow->id));
        $this->assertFalse($ids->contains($outsideWindow->id));
    }

    public function test_zero_quantity_inactive_and_null_expiry_excluded(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(5)->toDateString(),
            'current_quantity' => '0.00',
        ]);
        $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(5)->toDateString(),
            'is_active' => false,
        ]);
        $included = $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(5)->toDateString(),
        ]);
        $this->createItem(['expiry_date' => null]);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $ids = collect($this->getJson('/api/v1/inventory-items?expiry=alert')->json('data.items'))->pluck('id');

        $this->assertSame([$included->id], $ids->all());
    }

    public function test_invalid_expiry_param_returns_422(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $this->getJson('/api/v1/inventory-items?expiry=soon')->assertStatus(422);
    }

    public function test_alert_results_ordered_by_expiry_date_ascending(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $later = $this->createItem(['expiry_date' => $this->clinicToday()->addDays(10)->toDateString(), 'name' => 'Later']);
        $earlier = $this->createItem(['expiry_date' => $this->clinicToday()->subDays(2)->toDateString(), 'name' => 'Earlier']);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $names = collect($this->getJson('/api/v1/inventory-items?expiry=alert')->json('data.items'))->pluck('name');

        $this->assertSame(['Earlier', 'Later'], $names->all());
    }

    public function test_tenant_isolation_for_expiry_filter(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $this->createItem(['expiry_date' => $this->clinicToday()->addDays(3)->toDateString()]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        InventoryItem::factory()
            ->withQuantity('5.00')
            ->create(['expiry_date' => $this->clinicToday()->addDays(3)->toDateString()]);
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $this->assertCount(1, $this->getJson('/api/v1/inventory-items?expiry=alert')->json('data.items'));
    }

    public function test_low_stock_and_expiry_filters_combine_with_and(): void
    {
        Carbon::setTestNow($this->clinicToday()->copy()->utc());

        $match = $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(5)->toDateString(),
            'current_quantity' => '5.00',
            'minimum_threshold' => 10,
        ]);
        $this->createItem([
            'expiry_date' => $this->clinicToday()->addDays(5)->toDateString(),
            'current_quantity' => '50.00',
            'minimum_threshold' => 10,
        ]);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $ids = collect($this->getJson('/api/v1/inventory-items?low_stock=1&expiry=expiring')->json('data.items'))->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    public function test_clinic_timezone_midnight_boundary_against_utc(): void
    {
        config(['clinic.timezone' => 'Africa/Cairo']);

        // 2024-06-01 22:00 UTC = 2024-06-02 01:00 in Cairo — clinic "today" is June 2.
        Carbon::setTestNow(Carbon::parse('2024-06-01 22:00:00', 'UTC'));

        $expiresCairoToday = $this->createItem(['expiry_date' => '2024-06-02']);
        $expiresUtcYesterday = $this->createItem(['expiry_date' => '2024-06-01']);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $expiringIds = collect($this->getJson('/api/v1/inventory-items?expiry=expiring')->json('data.items'))->pluck('id');
        $expiredIds = collect($this->getJson('/api/v1/inventory-items?expiry=expired')->json('data.items'))->pluck('id');

        $this->assertTrue($expiringIds->contains($expiresCairoToday->id));
        $this->assertFalse($expiringIds->contains($expiresUtcYesterday->id));
        $this->assertTrue($expiredIds->contains($expiresUtcYesterday->id));
    }

    public function test_inventory_alerts_permission_defaults(): void
    {
        $receptionist = $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $doctor = UserRole::DOCTOR;
        $assistant = UserRole::ASSISTANT;

        $gate = app(ClinicPermissionGate::class);

        $this->assertTrue($gate->forUser($receptionist)['inventory:alerts']);
        $this->assertFalse(ClinicPermissionCatalog::baseline()['doctor']['inventory:alerts']);
        $this->assertFalse(ClinicPermissionCatalog::baseline()['assistant']['inventory:alerts']);
        $this->assertTrue(ClinicPermissionCatalog::baseline()['receptionist']['inventory:alerts']);

        $doctorUser = $this->actingAsTenantUser($this->tenant, $doctor);
        $this->assertFalse($gate->forUser($doctorUser)['inventory:alerts']);

        $assistantUser = $this->actingAsTenantUser($this->tenant, $assistant);
        $this->assertFalse($gate->forUser($assistantUser)['inventory:alerts']);
    }

    public function test_owner_can_grant_inventory_alerts_to_doctor(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);

        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['inventory:alerts'] = true;

        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        $doctor = $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);
        $this->assertTrue(app(ClinicPermissionGate::class)->forUser($doctor)['inventory:alerts']);
    }

    public function test_owner_can_revoke_inventory_alerts_from_receptionist(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);

        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['inventory:alerts'] = false;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        $receptionist = $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $this->assertFalse(app(ClinicPermissionGate::class)->forUser($receptionist)['inventory:alerts']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}

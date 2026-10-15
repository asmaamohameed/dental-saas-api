<?php

namespace Tests\Feature\Clinic;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use App\Support\WorkingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    private function sampleWeekPayload(): array
    {
        $payload = [];
        foreach (WorkingDay::values() as $day) {
            $payload[$day] = [
                'is_open' => $day !== WorkingDay::FRIDAY,
                'open' => '09:00',
                'close' => '21:00',
            ];
        }

        return $payload;
    }

    public function test_get_returns_defaults_when_unset(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/clinic/working-hours')
            ->assertOk()
            ->assertJsonPath('data.working_hours.saturday.is_open', true)
            ->assertJsonPath('data.working_hours.saturday.open', '09:00')
            ->assertJsonPath('data.working_hours.saturday.close', '21:00')
            ->assertJsonPath('data.timezone', config('clinic.timezone'));
    }

    public function test_owner_can_update_working_hours(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $payload = $this->sampleWeekPayload();

        $this->putJson('/api/v1/clinic/working-hours', $payload)
            ->assertOk()
            ->assertJsonPath('data.working_hours.friday.is_open', false);

        $this->assertSame($payload['saturday']['close'], $tenant->fresh()->working_hours['saturday']['close']);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => AuditAction::CLINIC_WORKING_HOURS_CHANGED->value,
        ]);
    }

    public function test_non_owner_cannot_update_working_hours(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);

        Sanctum::actingAs($receptionist, ['*']);

        $this->putJson('/api/v1/clinic/working-hours', $this->sampleWeekPayload())
            ->assertForbidden();

        $this->assertNull($tenant->fresh()->working_hours);
    }

    public function test_validation_requires_at_least_one_open_day(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $payload = $this->sampleWeekPayload();
        foreach (WorkingDay::values() as $day) {
            $payload[$day]['is_open'] = false;
        }

        $this->putJson('/api/v1/clinic/working-hours', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['working_hours']);
    }

    public function test_tenant_isolation_on_update(): void
    {
        $clinicA = Tenant::factory()->create();
        $clinicB = Tenant::factory()->create([
            'working_hours' => $this->sampleWeekPayload(),
        ]);

        app(CurrentTenant::class)->set($clinicA->id);

        $owner = User::factory()->create([
            'tenant_id' => $clinicA->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $payload = $this->sampleWeekPayload();
        $payload['saturday']['close'] = '18:00';

        $this->putJson('/api/v1/clinic/working-hours', $payload)->assertOk();

        $this->assertSame('18:00', $clinicA->fresh()->working_hours['saturday']['close']);
        $this->assertSame('21:00', $clinicB->fresh()->working_hours['saturday']['close']);
    }
}

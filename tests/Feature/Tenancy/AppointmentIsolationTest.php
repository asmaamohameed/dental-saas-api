<?php

namespace Tests\Feature\Tenancy;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class AppointmentIsolationTest extends TestCase
{
    public function test_user_cannot_view_an_appointment_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $appointmentB = Appointment::factory()->create();

        $this->actingAsTenantUser();

        $this->getJson("/api/v1/appointments/{$appointmentB->id}")
            ->assertNotFound();
    }

    public function test_appointments_index_only_returns_current_tenant_appointments(): void
    {
        $tenantA = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantA->id);
        $appointmentA = Appointment::factory()->create();

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        Appointment::factory()->count(3)->create();

        $this->actingAsTenantUser($tenantA);

        $ids = collect(
            $this->getJson('/api/v1/appointments')->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($appointmentA->id));
    }

    public function test_user_cannot_update_an_appointment_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $appointmentB = Appointment::factory()->create();

        $this->actingAsTenantUser();

        $this->putJson("/api/v1/appointments/{$appointmentB->id}", [
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ])->assertNotFound();
    }

    public function test_user_cannot_delete_an_appointment_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $appointmentB = Appointment::factory()->create();

        $this->actingAsTenantUser();

        $this->deleteJson("/api/v1/appointments/{$appointmentB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('appointments', ['id' => $appointmentB->id]);
    }

    public function test_user_cannot_update_status_of_an_appointment_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $appointmentB = Appointment::factory()->create();

        $this->actingAsTenantUser();

        $this->patchJson("/api/v1/appointments/{$appointmentB->id}/status", [
            'status' => 'completed',
        ])->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Api\V1;

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private User $receptionist;
    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'owner',
        ]);

        $this->receptionist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'receptionist',
        ]);

        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'doctor',
        ]);
    }

    public function test_can_list_services(): void
    {
        Service::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->doctor)->getJson('/api/v1/services');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data.data');
    }

    public function test_owner_and_receptionist_can_create_service(): void
    {
        $payload = [
            'name_ar' => 'خلع سن',
            'name_en' => 'Tooth Extraction',
            'default_price' => 150.00,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->receptionist)->postJson('/api/v1/services', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name_ar', 'خلع سن');

        $this->assertDatabaseHas('services', [
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'خلع سن',
        ]);
    }

    public function test_doctor_cannot_create_service(): void
    {
        $payload = [
            'name_ar' => 'تنظيف',
            'default_price' => 100.00,
        ];

        $response = $this->actingAs($this->doctor)->postJson('/api/v1/services', $payload);

        $response->assertStatus(403);
    }

    public function test_owner_can_delete_service_not_other(): void
    {
        $service = Service::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->owner)->deleteJson("/api/v1/services/{$service->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_receptionist_cannot_delete_service(): void
    {
        $service = Service::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->receptionist)->deleteJson("/api/v1/services/{$service->id}");

        $response->assertStatus(403);
    }

    public function test_cannot_delete_system_other_service(): void
    {
        $otherService = Service::factory()->other()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->owner)->deleteJson("/api/v1/services/{$otherService->id}");

        $response->assertStatus(422);
    }
}

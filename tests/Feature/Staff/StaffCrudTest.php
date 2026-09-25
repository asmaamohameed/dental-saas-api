<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->owner = User::factory()->owner()->for($this->tenant)->create();
    }

    // ── index() ──────────────────────────────────────────

    public function test_owner_can_list_staff(): void
    {
        User::factory()->doctor()->for($this->tenant)->create();
        User::factory()->receptionist()->for($this->tenant)->create();

        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_staff_list_excludes_the_owner(): void
    {
        User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->getJson('/api/v1/staff');

        $response->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertFalse(
            collect($response->json('data.items'))->contains('id', $this->owner->id)
        );
    }

    public function test_doctor_cannot_list_staff(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($doctor, ['*']);

        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_receptionist_cannot_list_staff(): void
    {
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();

        Sanctum::actingAs($receptionist, ['*']);

        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    // ── store() ──────────────────────────────────────────

    public function test_owner_can_create_a_doctor(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Dr. Test',
            'email' => 'doctor@example.com',
            'password' => 'password123',
            'role' => UserRole::DOCTOR->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', UserRole::DOCTOR->value);

        $this->assertDatabaseHas('users', [
            'email' => 'doctor@example.com',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_creating_a_doctor_with_working_days_persists_them(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/staff', [
            'name' => 'Dr. Schedule',
            'email' => 'schedule@example.com',
            'password' => 'password123',
            'role' => UserRole::DOCTOR->value,
            'working_days' => ['saturday', 'monday'],
        ])->assertCreated();

        $user = User::query()->where('email', 'schedule@example.com')->first();

        $this->assertSame(['saturday', 'monday'], $user->working_days);
    }

    public function test_working_days_submitted_for_a_receptionist_are_ignored(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/staff', [
            'name' => 'Front Desk',
            'email' => 'reception@example.com',
            'password' => 'password123',
            'role' => UserRole::RECEPTIONIST->value,
            'working_days' => ['saturday', 'monday'],
        ])->assertCreated();

        $user = User::query()->where('email', 'reception@example.com')->first();

        $this->assertNull($user->working_days);
    }

    public function test_cannot_create_an_owner_via_staff_endpoint(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Fake Owner',
            'email' => 'fakeowner@example.com',
            'password' => 'password123',
            'role' => UserRole::OWNER->value,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_store_fails_with_duplicate_email(): void
    {
        User::factory()->doctor()->for($this->tenant)->create(['email' => 'taken@example.com']);

        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->postJson('/api/v1/staff', [
            'name' => 'Dr. Duplicate',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'role' => UserRole::DOCTOR->value,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_non_owner_cannot_create_staff(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($doctor, ['*']);

        $this->postJson('/api/v1/staff', [
            'name' => 'Dr. New',
            'email' => 'new@example.com',
            'password' => 'password123',
            'role' => UserRole::DOCTOR->value,
        ])->assertForbidden();
    }

    // ── update() ─────────────────────────────────────────

    public function test_owner_can_update_a_staff_member(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->putJson("/api/v1/staff/{$doctor->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_owner_cannot_update_another_owner_via_staff_endpoint(): void
    {
        $otherOwner = User::factory()->owner()->for($this->tenant)->create();

        Sanctum::actingAs($this->owner, ['*']);

        $this->putJson("/api/v1/staff/{$otherOwner->id}", [
            'name' => 'Hijacked',
        ])->assertForbidden();
    }

    public function test_owner_cannot_update_staff_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(CurrentTenant::class)->set($otherTenant->id);
        $otherDoctor = User::factory()->doctor()->for($otherTenant)->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        Sanctum::actingAs($this->owner, ['*']);

        $this->putJson("/api/v1/staff/{$otherDoctor->id}", [
            'name' => 'Cross Tenant Update',
        ])->assertNotFound();
    }
    // toggleActive()

    public function test_owner_can_toggle_staff_active_status(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create(['is_active' => true]);

        Sanctum::actingAs($this->owner, ['*']);

        $response = $this->patchJson("/api/v1/staff/{$doctor->id}/toggle-active");

        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($doctor->fresh()->is_active);
    }

    public function test_owner_cannot_toggle_another_owner_active_status(): void
    {
        $otherOwner = User::factory()->owner()->for($this->tenant)->create();

        Sanctum::actingAs($this->owner, ['*']);

        $this->patchJson("/api/v1/staff/{$otherOwner->id}/toggle-active")->assertForbidden();
    }
}

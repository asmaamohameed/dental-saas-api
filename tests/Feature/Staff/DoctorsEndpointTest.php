<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_receptionist_can_list_doctors_for_booking(): void
    {
        User::factory()->doctor()->for($this->tenant)->create(['name' => 'Dr. A']);
        User::factory()->doctor()->for($this->tenant)->create(['name' => 'Dr. B']);
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();

        Sanctum::actingAs($receptionist, ['*']);

        $response = $this->getJson('/api/v1/doctors');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_doctor_can_list_doctors(): void
    {
        User::factory()->doctor()->for($this->tenant)->create();
        $doctor = User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($doctor, ['*']);

        $this->getJson('/api/v1/doctors')->assertOk();
    }

    public function test_owner_can_list_doctors(): void
    {
        User::factory()->doctor()->for($this->tenant)->create();

        Sanctum::actingAs($this->tenant->users()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password_hash' => bcrypt('password'),
            'role' => UserRole::OWNER,
        ]), ['*']);

        $this->getJson('/api/v1/doctors')->assertOk();
    }

    public function test_doctors_list_excludes_receptionists_and_owners(): void
    {
        User::factory()->doctor()->for($this->tenant)->create();
        User::factory()->receptionist()->for($this->tenant)->create();
        $owner = User::factory()->owner()->for($this->tenant)->create();

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson('/api/v1/doctors');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_doctors_list_excludes_inactive_doctors(): void
    {
        User::factory()->doctor()->for($this->tenant)->create(['is_active' => true]);
        User::factory()->doctor()->for($this->tenant)->create(['is_active' => false]);
        $owner = User::factory()->owner()->for($this->tenant)->create();

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson('/api/v1/doctors');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_doctors_list_is_scoped_to_current_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(CurrentTenant::class)->set($otherTenant->id);
        User::factory()->doctor()->for($otherTenant)->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        User::factory()->doctor()->for($this->tenant)->create();

        $owner = User::factory()->owner()->for($this->tenant)->create();
        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson('/api/v1/doctors');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_user_cannot_list_doctors(): void
    {
        $this->getJson('/api/v1/doctors')->assertUnauthorized();
    }

    public function test_doctors_with_day_param_orders_staff_working_that_day_first(): void
    {
        User::factory()->doctor()->for($this->tenant)->create([
            'name' => 'Dr. Saturday',
            'working_days' => ['saturday', 'monday'],
        ]);
        User::factory()->doctor()->for($this->tenant)->create([
            'name' => 'Dr. Sunday Only',
            'working_days' => ['sunday'],
        ]);
        User::factory()->assistant()->for($this->tenant)->create([
            'name' => 'Asst. Saturday',
            'working_days' => ['saturday'],
        ]);
        User::factory()->receptionist()->for($this->tenant)->create([
            'name' => 'Reception Saturday',
            'working_days' => ['saturday'],
        ]);

        $owner = User::factory()->owner()->for($this->tenant)->create();
        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson('/api/v1/doctors?day=saturday');

        $response->assertOk()->assertJsonCount(3, 'data');
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Asst. Saturday', 'Dr. Saturday', 'Dr. Sunday Only'], $names);

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertTrue($byName['Dr. Saturday']['works_on_day']);
        $this->assertTrue($byName['Asst. Saturday']['works_on_day']);
        $this->assertFalse($byName['Dr. Sunday Only']['works_on_day']);
    }

    public function test_invalid_day_query_parameter_returns_422(): void
    {
        $owner = User::factory()->owner()->for($this->tenant)->create();
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/v1/doctors?day=notaday')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day']);
    }
}

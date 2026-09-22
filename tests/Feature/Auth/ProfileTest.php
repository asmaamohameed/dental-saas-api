<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_their_profile_with_tenant_loaded(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.tenant.id', $tenant->id);
    }

    public function test_profile_response_never_exposes_the_password_hash(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertArrayNotHasKey('password_hash', $response->json('data.user'));
    }

    public function test_unauthenticated_request_cannot_view_profile(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_their_profile(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Original Name',
            'email' => 'original@clinic.com',
            'phone' => '1234567890',
            'locale' => 'en',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/v1/auth/me', [
            'name' => 'Updated Name',
            'email' => 'updated@clinic.com',
            'phone' => '9876543210',
            'locale' => 'ar',
        ])->assertOk();

        $response->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.name', 'Updated Name')
            ->assertJsonPath('data.user.email', 'updated@clinic.com')
            ->assertJsonPath('data.user.phone', '9876543210')
            ->assertJsonPath('data.user.locale', 'ar');

        $this->assertArrayNotHasKey('password_hash', $response->json('data.user'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@clinic.com',
            'phone' => '9876543210',
            'locale' => 'ar',
        ]);
    }

    public function test_authenticated_user_can_update_their_password(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password_hash' => Hash::make('old-password-123'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->putJson('/api/v1/auth/me', [
            'password' => 'new-secret-password-123',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-password-123', $user->password_hash));
    }

    public function test_user_cannot_update_to_an_email_already_taken_by_another_user(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user1 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'existing@clinic.com',
        ]);
        $user2 = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user2@clinic.com',
        ]);

        Sanctum::actingAs($user2, ['*']);

        $this->putJson('/api/v1/auth/me', [
            'email' => 'existing@clinic.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_keep_their_current_email_when_updating(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
            'email' => 'john@clinic.com',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->putJson('/api/v1/auth/me', [
            'name' => 'John Updated',
            'email' => 'john@clinic.com',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'John Updated')
            ->assertJsonPath('data.user.email', 'john@clinic.com');
    }

    public function test_user_cannot_change_their_role_or_active_status_via_profile_update(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::DOCTOR,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->putJson('/api/v1/auth/me', [
            'name' => 'Doctor John',
            'role' => UserRole::OWNER->value,
            'is_active' => false,
        ])->assertOk();

        $user->refresh();
        $this->assertEquals(UserRole::DOCTOR, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $this->putJson('/api/v1/auth/me', [
            'name' => 'Attacker',
        ])->assertUnauthorized();
    }
}

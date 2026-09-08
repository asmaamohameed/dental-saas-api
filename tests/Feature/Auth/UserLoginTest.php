<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLoginTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $attributes = []): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        return User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = $this->createUser([
            'email' => 'doctor@example.com',
            'password_hash' => bcrypt('secret-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'doctor@example.com',
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.tenant.id', $user->tenant_id)
            ->assertJsonStructure(['data' => ['access_token', 'user' => ['id', 'email', 'tenant']]]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'api_token',
        ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->createUser(['password_hash' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->createUser([
            'password_hash' => bcrypt('secret-password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
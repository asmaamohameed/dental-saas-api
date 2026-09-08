<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_logout_deletes_the_current_access_token(): void
    {
        $user = $this->createUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('data.access_token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        app('auth')->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_request_cannot_logout(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_logout(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/admin/auth/logout')->assertOk();
    }

    public function test_logout_deletes_the_current_admin_access_token(): void
    {
        $admin = AdminUser::factory()->create(['password_hash' => bcrypt('secret-password')]);

        $login = $this->postJson('/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk();

        $token = $login->json('data.access_token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        app('auth')->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/admin/ping')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_request_cannot_logout(): void
    {
        $this->postJson('/admin/auth/logout')->assertUnauthorized();
    }

    public function test_regular_user_cannot_use_admin_logout(): void
    {
        $this->postJson('/admin/auth/logout')->assertUnauthorized();
    }
}
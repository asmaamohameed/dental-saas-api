<?php

namespace Tests\Unit\Services;

use App\Models\AdminUser;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_admin_deletes_the_current_access_token(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('admin_api_token');
        $admin->withAccessToken($token->accessToken);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        app(AuthService::class)->logoutAdmin($admin);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}

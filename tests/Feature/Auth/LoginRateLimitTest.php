<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_sixth_login_attempt_within_a_minute_is_throttled(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', $payload);
            $this->assertNotEquals(429, $response->getStatusCode(), "Attempt #{$i} unexpectedly throttled.");
        }

        $response = $this->postJson('/api/v1/auth/login', $payload);
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    public function test_login_attempts_for_a_different_email_are_not_throttled_by_another_users_attempts(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $userA = User::factory()->create(['tenant_id' => $tenant->id]);
        $userB = User::factory()->create(['tenant_id' => $tenant->id]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $userA->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $userB->email,
            'password' => 'wrong-password',
        ]);

        $this->assertNotEquals(429, $response->getStatusCode());
    }
}

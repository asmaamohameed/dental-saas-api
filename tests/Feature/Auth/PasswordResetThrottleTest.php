<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_forgot_password_endpoint_throttles_after_six_attempts_per_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => 'someone@example.com',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Attempt #{$i} unexpectedly throttled.");
        }

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'someone@example.com',
        ]);

        $response->assertStatus(429);
    }

    public function test_reset_password_endpoint_throttles_after_six_attempts_per_minute(): void
    {
        $payload = [
            'email' => 'someone@example.com',
            'token' => 'invalid-token',
            'password' => 'SomePass123!',
            'password_confirmation' => 'SomePass123!',
        ];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/reset-password', $payload);
            $this->assertNotEquals(429, $response->getStatusCode(), "Attempt #{$i} unexpectedly throttled.");
        }

        $response = $this->postJson('/api/v1/auth/reset-password', $payload);
        $response->assertStatus(429);
    }

    public function test_forgot_and_reset_password_endpoints_currently_share_the_same_rate_limit_bucket(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'shared@example.com']);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/auth/reset-password', [
                'email' => 'shared@example.com',
                'token' => 'invalid-token',
                'password' => 'SomePass123!',
                'password_confirmation' => 'SomePass123!',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode());
        }

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'shared@example.com']);
        $response->assertStatus(429);
    }
}

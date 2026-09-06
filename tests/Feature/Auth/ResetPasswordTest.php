<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function requestResetTokenFor(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        $capturedToken = null;

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function ($notification) use (&$capturedToken) {
                $capturedToken = $notification->token;

                return true;
            }
        );

        $this->assertNotNull($capturedToken, 'Failed to capture reset token from the notification.');

        return $capturedToken;
    }

    public function test_valid_token_resets_password_and_fires_event(): void
    {
        $user = $this->createUser();
        $token = $this->requestResetTokenFor($user);

        Event::fake([PasswordReset::class]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewStrongPass123!', $user->password_hash));

        Event::assertDispatched(PasswordReset::class, fn ($event) => $event->user->is($user));
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'clearly-not-a-real-token',
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertStatus(422);

        $user->refresh();
        $this->assertFalse(Hash::check('NewStrongPass123!', $user->password_hash));
    }

    public function test_reset_password_requires_password_confirmation_to_match(): void
    {
        $user = $this->createUser();
        $token = $this->requestResetTokenFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'DifferentPass456!',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_token_cannot_be_reused_after_a_successful_reset(): void
    {
        $user = $this->createUser();
        $token = $this->requestResetTokenFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'FirstNewPass123!',
            'password_confirmation' => 'FirstNewPass123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'SecondNewPass456!',
            'password_confirmation' => 'SecondNewPass456!',
        ])->assertStatus(422);
    }
}

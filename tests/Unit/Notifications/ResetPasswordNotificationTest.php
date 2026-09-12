<?php

namespace Tests\Unit\Notifications;

use App\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class ResetPasswordNotificationTest extends TestCase
{
    public function test_via_uses_mail_channel_only(): void
    {
        $notification = new ResetPasswordNotification('token123', 'user@example.com');

        $this->assertSame(['mail'], $notification->via(null));
    }

    public function test_to_mail_builds_correct_reset_url(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);

        $notification = new ResetPasswordNotification('abc123', 'user@example.com');
        $mail = $notification->toMail(null);

        $this->assertSame(
            'https://app.example.com/reset-password?token=abc123&email=user%40example.com',
            $mail->actionUrl
        );
    }

    public function test_to_mail_strips_trailing_slash_from_frontend_url(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);

        $notification = new ResetPasswordNotification('abc123', 'user@example.com');
        $mail = $notification->toMail(null);

        $this->assertStringNotContainsString('.com//reset-password', $mail->actionUrl);
    }
}

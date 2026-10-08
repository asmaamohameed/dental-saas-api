<?php

namespace Tests\Feature\Performance;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RequestTimingTest extends TestCase
{
    public function test_api_response_includes_server_timing_header(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $response = $this->getJson('/api/v1/appointments');

        $response->assertOk();
        $this->assertTrue($response->headers->has('Server-Timing'));
        $this->assertMatchesRegularExpression(
            '/^app;dur=\d+\.\d$/',
            $response->headers->get('Server-Timing')
        );
    }

    public function test_slow_request_logs_route_pattern_without_id_or_query_string(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        config(['performance.slow_request_threshold_ms' => 0]);
        Log::spy();

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status?debug=1", [
            'status' => 'checked_in',
        ])->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($appointment): bool {
                if ($message !== 'Slow request') {
                    return false;
                }

                $encoded = json_encode($context);

                $this->assertSame('PATCH', $context['method']);
                $this->assertSame('api/v1/appointments/{appointment}/status', $context['route']);
                $this->assertArrayHasKey('status', $context);
                $this->assertArrayHasKey('duration_ms', $context);
                $this->assertStringNotContainsString($appointment->id, $encoded);
                $this->assertStringNotContainsString('debug=1', $encoded);
                $this->assertStringNotContainsString('?', $context['route']);

                return true;
            })
            ->once();
    }

    public function test_disabled_timing_adds_no_header_and_no_slow_request_log(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);

        config(['performance.request_timing_enabled' => false]);
        Log::spy();

        $response = $this->getJson('/api/v1/appointments');

        $response->assertOk();
        $response->assertHeaderMissing('Server-Timing');

        Log::shouldNotHaveReceived('warning', function (string $message): bool {
            return $message === 'Slow request';
        });
    }

    public function test_slow_query_log_never_includes_bindings(): void
    {
        Log::spy();

        $connection = DB::connection();
        $connection->resetTotalQueryDuration();
        $connection->allowQueryDurationHandlersToRunAgain();

        $secret = 'unique-secret-binding-xyz';
        DB::select('select pg_sleep(0.6), ?::text as marker', [$secret]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($secret): bool {
                if ($message !== 'Slow query') {
                    return false;
                }

                $this->assertArrayHasKey('sql', $context);
                $this->assertArrayHasKey('duration_ms', $context);
                $this->assertArrayNotHasKey('bindings', $context);
                $this->assertStringNotContainsString($secret, $context['sql']);
                $this->assertStringNotContainsString($secret, (string) json_encode($context));

                return true;
            })
            ->once();
    }
}

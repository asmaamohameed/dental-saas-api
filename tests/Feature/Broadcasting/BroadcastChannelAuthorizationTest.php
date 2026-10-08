<?php

namespace Tests\Feature\Broadcasting;

use App\Events\PatientCheckedIn;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channels are bound to the default driver at boot (null in phpunit.xml).
        require base_path('routes/channels.php');
    }

    private function privateDoctorChannelName(string $tenantId, string $doctorId): string
    {
        return "private-tenant.{$tenantId}.doctor.{$doctorId}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postBroadcastAuth(array $data, ?string $token = null): TestResponse
    {
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            return $this->withToken($token)->post('/broadcasting/auth', $data, $headers);
        }

        return $this->post('/broadcasting/auth', $data, $headers);
    }

    private function bearerTokenFor(User $user): string
    {
        return $user->createToken('api_token')->plainTextToken;
    }

    public function test_doctor_can_authorize_own_private_channel(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $doctor = User::factory()->doctor()->create();

        $channelName = $this->privateDoctorChannelName($doctor->tenant_id, $doctor->id);

        $response = $this->withToken($this->bearerTokenFor($doctor))
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $channelName,
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonStructure(['auth']);
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_other_doctor_in_same_tenant_cannot_authorize_channel(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $channelDoctor = User::factory()->doctor()->create();
        $otherDoctor = User::factory()->doctor()->create();

        $response = $this->postBroadcastAuth([
            'socket_id' => '1234.5678',
            'channel_name' => $this->privateDoctorChannelName($tenant->id, $channelDoctor->id),
        ], $this->bearerTokenFor($otherDoctor));

        $response->assertForbidden();
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_doctor_from_other_tenant_cannot_authorize_channel(): void
    {
        $tenantA = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantA->id);
        $doctorA = User::factory()->doctor()->create();

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $doctorB = User::factory()->doctor()->create();

        $response = $this->postBroadcastAuth([
            'socket_id' => '1234.5678',
            'channel_name' => $this->privateDoctorChannelName($tenantA->id, $doctorA->id),
        ], $this->bearerTokenFor($doctorB));

        $response->assertForbidden();
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_owner_cannot_authorize_doctor_private_channel(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $doctor = User::factory()->doctor()->create();
        $owner = User::factory()->owner()->create();

        $response = $this->postBroadcastAuth([
            'socket_id' => '1234.5678',
            'channel_name' => $this->privateDoctorChannelName($tenant->id, $doctor->id),
        ], $this->bearerTokenFor($owner));

        $response->assertForbidden();
    }

    public function test_receptionist_cannot_authorize_doctor_private_channel(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $doctor = User::factory()->doctor()->create();
        $receptionist = User::factory()->receptionist()->create();

        $response = $this->postBroadcastAuth([
            'socket_id' => '1234.5678',
            'channel_name' => $this->privateDoctorChannelName($tenant->id, $doctor->id),
        ], $this->bearerTokenFor($receptionist));

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401_without_session_cookie(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $doctor = User::factory()->doctor()->create();

        $response = $this->postBroadcastAuth([
            'socket_id' => '1234.5678',
            'channel_name' => $this->privateDoctorChannelName($tenant->id, $doctor->id),
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString('login', strtolower($response->headers->get('Location', '')));
    }

    public function test_patient_checked_in_broadcast_channel_matches_channel_authorization_pattern(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $doctor = User::factory()->doctor()->create();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $doctor->tenant_id,
            'doctor_id' => $doctor->id,
        ]);

        $event = new PatientCheckedIn($appointment);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);

        $expected = $this->privateDoctorChannelName($doctor->tenant_id, $doctor->id);
        $this->assertSame($expected, $channels[0]->name);

        $this->withToken($this->bearerTokenFor($doctor))
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => $expected,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}

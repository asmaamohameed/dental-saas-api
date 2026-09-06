<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotFoundResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_patient_returns_generic_404_message_without_leaking_model_or_id(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);
        Sanctum::actingAs($user, ['*']);

        $nonExistentId = (string) Str::uuid();

        $response = $this->getJson("/api/v1/patients/{$nonExistentId}");

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Resource not found.');

        $body = $response->getContent();
        $this->assertStringNotContainsString('App\\Models\\Patient', $body);
        $this->assertStringNotContainsString($nonExistentId, $body);
    }
}

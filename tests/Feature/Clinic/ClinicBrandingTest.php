<?php

namespace Tests\Feature\Clinic;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_clinic_logo_to_public_directory(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::OWNER,
        ]);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->image('clinic-logo.png', 120, 40),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tenant.logo_url', fn ($url) => is_string($url) && str_contains($url, 'tenant-logos/'.$tenant->id));

        $tenant->refresh();
        $this->assertNotNull($tenant->logo_path);
        $this->assertFileExists(public_path($tenant->logo_path));

        File::deleteDirectory(public_path('tenant-logos/'.$tenant->id));
    }

    public function test_receptionist_cannot_upload_clinic_logo(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $receptionist = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);

        Sanctum::actingAs($receptionist, ['*']);

        $this->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->image('clinic-logo.png'),
        ])->assertForbidden();
    }
}

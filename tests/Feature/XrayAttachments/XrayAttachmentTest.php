<?php

namespace Tests\Feature\XrayAttachments;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\XrayAttachment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XrayAttachmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_clinical_user_can_upload_an_xray_attachment(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $file = UploadedFile::fake()->create('scan.jpg', 100, 'image/jpeg');

        $response = $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => $file,
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertSame('image/jpeg', $data['file_type']);
        $this->assertArrayHasKey('temporary_url', $data);
        $this->assertArrayNotHasKey('file_url', $data);

        $attachment = XrayAttachment::query()->first();
        $this->assertNotNull($attachment);
        $this->assertSame($patient->id, $attachment->patient_id);
        $this->assertSame($user->id, $attachment->uploaded_by);
        Storage::disk('s3')->assertExists($attachment->file_url);
        $this->assertStringStartsWith("xrays/{$patient->tenant_id}/{$patient->id}/", $attachment->file_url);
    }

    public function test_upload_rejects_invalid_file_type(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => $file,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('xray_attachments', 0);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $file = UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf');

        $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => $file,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('xray_attachments', 0);
    }

    public function test_index_returns_temporary_urls_not_storage_paths(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $path = "xrays/{$patient->tenant_id}/{$patient->id}/sample.jpg";
        Storage::disk('s3')->put($path, 'image-bytes');

        XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'appointment_id' => null,
            'file_url' => $path,
            'file_type' => 'image/jpeg',
        ]);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/xray-attachments")->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertArrayHasKey('temporary_url', $items[0]);
        $this->assertNotSame($path, $items[0]['temporary_url']);
        $this->assertArrayNotHasKey('file_url', $items[0]);
        $this->assertStringStartsWith('http', (string) $items[0]['temporary_url']);
    }

    public function test_destroy_removes_s3_object_and_database_row(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $path = "xrays/{$patient->tenant_id}/{$patient->id}/to-delete.jpg";
        Storage::disk('s3')->put($path, 'image-bytes');

        $attachment = XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'appointment_id' => null,
            'file_url' => $path,
            'file_type' => 'image/jpeg',
        ]);

        $this->deleteJson("/api/v1/patients/{$patient->id}/xray-attachments/{$attachment->id}")
            ->assertOk();

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseMissing('xray_attachments', ['id' => $attachment->id]);
    }

    public function test_user_from_another_tenant_cannot_access_or_delete_an_attachment(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $doctor = User::factory()->doctor()->create();
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create();
        $path = "xrays/{$tenant->id}/{$patient->id}/protected.jpg";
        Storage::disk('s3')->put($path, 'image-bytes');

        $attachment = XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'appointment_id' => null,
            'file_url' => $path,
            'file_type' => 'image/jpeg',
        ]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->owner()->create(['tenant_id' => $otherTenant->id]);
        Sanctum::actingAs($outsider, ['*']);

        $this->getJson("/api/v1/patients/{$patient->id}/xray-attachments")->assertNotFound();

        $this->deleteJson("/api/v1/patients/{$patient->id}/xray-attachments/{$attachment->id}")
            ->assertNotFound();

        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('xray_attachments', ['id' => $attachment->id]);
    }
}

<?php

namespace Tests\Feature\XrayAttachments;

use App\Enums\UserRole;
use App\Models\Appointment;
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

    public function test_upload_can_be_linked_to_an_appointment(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $upload = $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => UploadedFile::fake()->image('xray.png', 800, 600),
            'appointment_id' => $appointment->id,
        ])->assertCreated();

        $attachmentId = $upload->json('data.id');
        $this->assertIsString($attachmentId);
        $this->assertSame($appointment->id, $upload->json('data.appointment_id'));
        $this->assertStringStartsWith('http', (string) $upload->json('data.temporary_url'));

        $objectKey = XrayAttachment::query()->findOrFail($attachmentId)->file_url;
        Storage::disk('s3')->assertExists($objectKey);

        $this->getJson("/api/v1/patients/{$patient->id}/xray-attachments")
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $attachmentId);

        $this->deleteJson("/api/v1/patients/{$patient->id}/xray-attachments/{$attachmentId}")
            ->assertOk();

        Storage::disk('s3')->assertMissing($objectKey);
        $this->assertDatabaseMissing('xray_attachments', ['id' => $attachmentId]);
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

    public function test_receptionist_cannot_list_upload_or_delete_xray_attachments(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $attachment = XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'file_url' => 'tenants/test/patients/test/xrays/sample.png',
        ]);

        $this->getJson("/api/v1/patients/{$patient->id}/xray-attachments")->assertForbidden();

        $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => UploadedFile::fake()->image('xray.png'),
        ])->assertForbidden();

        $this->deleteJson("/api/v1/patients/{$patient->id}/xray-attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_user_from_another_tenant_cannot_access_or_delete_an_attachment(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $path = "xrays/{$patient->tenant_id}/{$patient->id}/protected.jpg";
        Storage::disk('s3')->put($path, 'image-bytes');

        $attachment = XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'appointment_id' => null,
            'file_url' => $path,
            'file_type' => 'image/jpeg',
        ]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->doctor()->create(['tenant_id' => $otherTenant->id]);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/patients/{$patient->id}/xray-attachments")->assertNotFound();
        $this->postJson("/api/v1/patients/{$patient->id}/xray-attachments", [
            'file' => UploadedFile::fake()->image('xray.png'),
        ])->assertNotFound();
        $this->deleteJson("/api/v1/patients/{$patient->id}/xray-attachments/{$attachment->id}")
            ->assertNotFound();

        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('xray_attachments', ['id' => $attachment->id]);
    }

    public function test_cannot_delete_xray_attachment_under_wrong_patient_route(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $otherPatient = Patient::factory()->create();
        $attachment = XrayAttachment::factory()->create([
            'patient_id' => $patient->id,
            'file_url' => 'tenants/test/patients/test/xrays/sample.png',
        ]);

        $this->deleteJson("/api/v1/patients/{$otherPatient->id}/xray-attachments/{$attachment->id}")
            ->assertNotFound();
    }
}

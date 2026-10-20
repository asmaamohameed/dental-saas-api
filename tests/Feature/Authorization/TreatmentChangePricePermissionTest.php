<?php

namespace Tests\Feature\Authorization;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentToothStatus;
use App\Enums\TreatmentType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreatmentChangePricePermissionTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_owner_and_doctor_can_change_prices_by_default(): void
    {
        $owner = $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $doctor = $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $this->assertTrue(Gate::forUser($owner)->allows('canChangePrice'));
        $this->assertTrue(Gate::forUser($doctor)->allows('canChangePrice'));
    }

    public function test_receptionist_and_assistant_cannot_change_prices_by_default(): void
    {
        $receptionist = $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $assistant = $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);

        $this->assertFalse(Gate::forUser($receptionist)->allows('canChangePrice'));
        $this->assertFalse(Gate::forUser($assistant)->allows('canChangePrice'));
    }

    public function test_owner_can_grant_change_price_to_receptionist(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'price' => 50,
            'description' => 'Manual charge line',
            'quantity' => 1,
        ])->assertForbidden();

        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['treatment:change_price'] = true;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'price' => 50,
            'description' => 'Manual charge line',
            'quantity' => 1,
        ])->assertCreated();
    }

    public function test_receptionist_with_change_price_permission_can_create_treatment_with_custom_agreed_price(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['treatment:change_price'] = true;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ]);

        Sanctum::actingAs($receptionist, ['*']);
        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
            'agreed_price' => 150,
        ])
            ->assertCreated()
            ->assertJsonPath('data.agreed_price', 150);
    }

    public function test_assistant_cannot_patch_tooth_price_without_permission(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $treatment = $this->createTreatmentForPriceTest();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 50]],
        ])->assertForbidden();
    }

    private function createTreatmentForPriceTest(): PatientTreatment
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => 100,
            'clinical_status' => PatientTreatmentStatus::PLANNED,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);

        PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '16',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => 100,
        ]);

        return $treatment;
    }
}

<?php

namespace Tests\Unit\Middleware;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentType;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureUserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function patientTreatmentForTenant(string $tenantId): PatientTreatment
    {
        app(CurrentTenant::class)->set($tenantId);

        $patient = Patient::factory()->create(['tenant_id' => $tenantId]);
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        return PatientTreatment::query()->create([
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
    }

    public function test_receptionist_is_forbidden_on_session_recording_route(): void
    {
        $user = $this->actingAsRole(UserRole::RECEPTIONIST);
        $treatment = $this->patientTreatmentForTenant($user->tenant_id);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [])
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden. Insufficient role permissions.']);
    }

    public function test_doctor_passes_the_session_recording_role_gate(): void
    {
        $user = $this->actingAsRole(UserRole::DOCTOR);
        $treatment = $this->patientTreatmentForTenant($user->tenant_id);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'not-a-valid-status',
        ])->assertStatus(422);
    }

    public function test_doctor_is_forbidden_on_an_owner_only_route(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(403);
    }

    public function test_receptionist_is_forbidden_on_an_owner_only_route(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(403);
    }
}

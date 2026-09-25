<?php

namespace Tests\Feature\Treatments;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentSessionStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Component;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreatmentTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_editing_a_template_creates_a_new_version_and_leaves_old_treatments_linked(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'agreed_price' => 200,
        ])->assertCreated();

        $this->putJson("/api/v1/treatment-templates/{$template->id}", [
            'name_ar' => 'حشوة محدثة',
            'name_en' => 'Updated Filling',
            'category' => 'medical',
            'default_price' => 350,
            'estimated_duration_minutes' => 45,
            'steps' => [[
                'step_order' => 1,
                'name_ar' => 'تحضير',
                'name_en' => 'Prep',
                'default_duration_minutes' => 20,
                'is_required' => true,
                'is_repeatable' => false,
            ]],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $template->refresh();
        $this->assertFalse((bool) $template->is_current);

        $treatment = PatientTreatment::query()->first();
        $this->assertSame($template->id, $treatment->treatment_template_id);
        $this->assertSame(1, $treatment->treatment_template_version);
        $this->assertSame('200.00', (string) $treatment->agreed_price);
    }

    public function test_treatments_accept_zero_one_or_many_teeth(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $none = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => [],
        ])->assertCreated();
        $this->assertSame([], $none->json('data.tooth_numbers'));

        $one = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated();
        $this->assertSame(['16'], $one->json('data.tooth_numbers'));

        $many = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['11', '21'],
        ])->assertCreated();
        $this->assertEqualsCanonicalizing(['11', '21'], $many->json('data.tooth_numbers'));
    }

    public function test_cancelling_requires_a_reason_and_does_not_change_financial_status_when_unpaid(): void
    {
        $treatment = $this->createTreatment();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'cancelled',
        ])->assertUnprocessable();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'cancelled',
            'cancellation_reason' => 'Patient declined',
        ])->assertOk()
            ->assertJsonPath('data.clinical_status', 'cancelled')
            ->assertJsonPath('data.financial_status', 'unpaid');
    }

    public function test_retreatment_marks_the_original_failed_and_links_a_new_row(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['26']]);

        $response = $this->postJson("/api/v1/patient-treatments/{$treatment->id}/retreat", [
            'agreed_price' => 0,
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::FAILED, $treatment->clinical_status);
        $this->assertSame($treatment->id, $response->json('data.parent_treatment_id'));
        $this->assertSame('retreatment', $response->json('data.treatment_type'));
        $this->assertSame(['26'], $response->json('data.tooth_numbers'));
        $this->assertSame(0, (int) $response->json('data.agreed_price'));
    }

    public function test_appointment_no_show_does_not_change_clinical_status(): void
    {
        $treatment = $this->createTreatment();
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $treatment->patient_id,
            'doctor_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => AppointmentStatus::SCHEDULED,
            'appointment_type' => AppointmentType::TREATMENT_VISIT,
        ]);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'appointment_id' => $appointment->id,
            'status' => 'scheduled',
        ])->assertCreated();

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'no_show',
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::PLANNED, $treatment->clinical_status);
        $this->assertSame(TreatmentSessionStatus::NO_SHOW, $treatment->sessions()->first()->status);
    }

    public function test_completed_treatment_can_remain_unpaid(): void
    {
        $template = $this->createTemplate();
        $treatment = $this->createTreatment([
            'treatment_template_id' => $template->id,
            'agreed_price' => 150,
        ]);

        $session = $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'completed',
            'steps' => [[
                'treatment_template_step_id' => $template->steps()->first()->id,
                'status' => 'done',
            ]],
        ])->assertCreated();

        $this->assertNotEmpty($session->json('data.session.id'));

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::COMPLETED, $treatment->clinical_status);
        $this->assertSame(FinancialStatus::UNPAID, $treatment->financial_status);
    }

    public function test_scheduling_the_last_required_step_does_not_complete_the_treatment(): void
    {
        $template = $this->createTemplate();
        $treatment = $this->createTreatment([
            'treatment_template_id' => $template->id,
        ]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'in_progress',
        ])->assertOk();

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
            'steps' => [[
                'treatment_template_step_id' => $template->steps()->first()->id,
                'status' => 'done',
            ]],
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::IN_PROGRESS, $treatment->clinical_status);
        $this->assertNull($treatment->completed_at);
    }

    public function test_non_repeatable_step_cannot_be_done_twice(): void
    {
        $template = $this->createTemplate();
        $treatment = $this->createTreatment(['treatment_template_id' => $template->id]);
        $stepId = $template->steps()->first()->id;

        $sessionId = $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
            'steps' => [[
                'treatment_template_step_id' => $stepId,
                'status' => 'done',
            ]],
        ])->json('data.session.id');

        $this->postJson("/api/v1/treatment-sessions/{$sessionId}/steps", [
            'treatment_template_step_id' => $stepId,
            'status' => 'done',
        ])->assertUnprocessable();
    }

    public function test_repeatable_step_increments_occurrence_number(): void
    {
        $template = $this->createTemplate(repeatable: true);
        $treatment = $this->createTreatment(['treatment_template_id' => $template->id]);
        $stepId = $template->steps()->first()->id;

        $sessionId = $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
        ])->json('data.session.id');

        $first = $this->postJson("/api/v1/treatment-sessions/{$sessionId}/steps", [
            'treatment_template_step_id' => $stepId,
            'status' => 'done',
        ])->assertCreated();
        $this->assertSame(1, $first->json('data.occurrence_number'));

        $second = $this->postJson("/api/v1/treatment-sessions/{$sessionId}/steps", [
            'treatment_template_step_id' => $stepId,
            'status' => 'done',
        ])->assertCreated();
        $this->assertSame(2, $second->json('data.occurrence_number'));
    }

    public function test_next_treatment_serializes_when_sessions_have_steps(): void
    {
        $template = $this->createTemplate();
        $treatment = $this->createTreatment(['treatment_template_id' => $template->id]);
        $stepId = $template->steps()->first()->id;

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
            'steps' => [[
                'treatment_template_step_id' => $stepId,
                'status' => 'skipped',
            ]],
        ])->assertCreated();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'in_progress',
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$treatment->patient_id}/next-treatment")
            ->assertOk()
            ->assertJsonPath('data.id', $treatment->id)
            ->assertJsonPath('data.sessions.0.steps.0.treatment_template_step_id', $stepId);
    }

    public function test_odontogram_treatment_status_uses_failed_over_in_progress_over_planned(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
            'clinical_status' => 'planned',
        ])->assertCreated();

        $inProgress = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$inProgress}/status", [
            'clinical_status' => 'in_progress',
        ])->assertOk();

        $failed = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$failed}/status", [
            'clinical_status' => 'in_progress',
        ])->assertOk();
        $this->patchJson("/api/v1/patient-treatments/{$failed}/status", [
            'clinical_status' => 'failed',
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonPath('data.treatment_status_by_tooth.16', 'failed');
    }

    public function test_completing_session_deducts_component_stock(): void
    {
        $component = Component::query()->create([
            'name_ar' => 'قفاز',
            'name_en' => 'Gloves',
            'default_price' => 10,
            'unit' => 'box',
            'current_quantity' => 20,
            'minimum_threshold' => 5,
            'is_active' => true,
        ]);

        $template = $this->createTemplate(componentId: $component->id, quantity: 3);
        $treatment = $this->createTreatment(['treatment_template_id' => $template->id]);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'completed',
            'steps' => [[
                'treatment_template_step_id' => $template->steps()->first()->id,
                'status' => 'done',
            ]],
        ])->assertCreated();

        $component->refresh();
        $this->assertSame('17.00', (string) $component->current_quantity);
    }

    public function test_payment_updates_financial_status_without_changing_clinical_status(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100]);
        $summary = $this->getJson("/api/v1/patient-treatments/{$treatment->id}/invoice-summary")->assertOk();
        $invoiceId = $summary->json('data.invoice_id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::PLANNED, $treatment->clinical_status);
        $this->assertSame(FinancialStatus::PAID, $treatment->financial_status);
        $this->assertSame(ConsentStatus::NOT_REQUIRED, $treatment->consent_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTreatment(array $overrides = []): PatientTreatment
    {
        $template = isset($overrides['treatment_template_id'])
            ? TreatmentTemplate::query()->findOrFail($overrides['treatment_template_id'])
            : $this->createTemplate();

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $payload = array_merge([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'agreed_price' => 100,
        ], $overrides);

        $id = $this->postJson('/api/v1/patient-treatments', $payload)->assertCreated()->json('data.id');

        return PatientTreatment::query()->findOrFail($id);
    }

    private function createTemplate(bool $repeatable = false, ?string $componentId = null, float $quantity = 1): TreatmentTemplate
    {
        $step = [
            'step_order' => 1,
            'name_ar' => 'خطوة',
            'name_en' => 'Main step',
            'default_duration_minutes' => 30,
            'is_required' => true,
            'is_repeatable' => $repeatable,
            'components' => $componentId ? [[
                'component_id' => $componentId,
                'quantity' => $quantity,
                'free_quantity' => 0,
            ]] : [],
        ];

        $id = $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'default_price' => 250,
            'estimated_duration_minutes' => 30,
            'steps' => [$step],
        ])->assertCreated()->json('data.id');

        return TreatmentTemplate::query()->with('steps.components')->findOrFail($id);
    }
}

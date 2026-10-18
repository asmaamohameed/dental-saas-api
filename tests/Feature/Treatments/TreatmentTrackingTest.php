<?php

namespace Tests\Feature\Treatments;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\AuditAction;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\RefundReasonCode;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentSessionStatus;
use App\Enums\TreatmentToothStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Services\DefaultTreatmentTemplatesProvisioner;
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

    public function test_template_update_edits_in_place_without_versioning(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'agreed_price' => 200,
            'tooth_numbers' => ['16'],
        ])->assertCreated();

        $this->putJson("/api/v1/treatment-templates/{$template->id}", [
            'name_ar' => 'حشوة محدثة',
            'name_en' => 'Updated Filling',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 350,
            'estimated_duration_minutes' => 45,
        ])->assertOk()->assertJsonPath('data.default_price', 350);

        $this->assertSame(1, TreatmentTemplate::query()->count());
    }

    public function test_tooth_scope_requires_at_least_one_tooth(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => [],
        ])->assertUnprocessable();
    }

    public function test_non_tooth_scope_rejects_tooth_numbers(): void
    {
        $template = $this->createTemplate(scope: TreatmentScope::FULL_MOUTH);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertUnprocessable();
    }

    public function test_per_tooth_price_is_copied_from_unit_price_at_creation(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 150, 'tooth_numbers' => ['16', '26']]);

        $this->assertSame(300.0, (float) $treatment->fresh(['teeth'])->total_price);
        $this->assertSame('150.00', (string) $treatment->teeth()->where('tooth_number', '16')->value('price'));
    }

    public function test_unit_price_change_updates_only_planned_and_in_progress_teeth(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [
                ['tooth_number' => '16', 'status' => 'done'],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'agreed_price' => 200,
        ])->assertOk();

        $this->assertSame('100.00', (string) $treatment->teeth()->where('tooth_number', '16')->value('price'));
        $this->assertSame('200.00', (string) $treatment->teeth()->where('tooth_number', '26')->value('price'));
    }

    public function test_session_rejects_inactive_dentist_id(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $inactive = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::DOCTOR,
            'is_active' => false,
        ]);
        Sanctum::actingAs($doctor, ['*']);

        $treatment = $this->createTreatment();

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'dentist_id' => $inactive->id,
            'status' => 'scheduled',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['dentist_id']);
    }

    public function test_assistant_can_change_tooth_price(): void
    {
        $assistant = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ASSISTANT]);
        Sanctum::actingAs($assistant, ['*']);

        $treatment = $this->createTreatment();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 50]],
        ])->assertOk();

        $this->assertSame('50.00', (string) $treatment->teeth()->where('tooth_number', '16')->value('price'));
    }

    public function test_receptionist_cannot_use_teeth_endpoint(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $treatment = $this->createTreatment();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertForbidden();
    }

    public function test_receptionist_forbidden_on_clinical_status_retreat_delete_and_sessions(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'in_progress',
        ])->assertForbidden();

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/retreat", [])->assertForbidden();

        $this->deleteJson("/api/v1/patient-treatments/{$treatment->id}")->assertForbidden();

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
        ])->assertForbidden();
    }

    public function test_assistant_cannot_reopen_done_tooth(): void
    {
        $assistant = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ASSISTANT]);
        Sanctum::actingAs($assistant, ['*']);

        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);

        Sanctum::actingAs($this->user, ['*']);
        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'done']],
        ])->assertOk();

        Sanctum::actingAs($assistant, ['*']);
        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertForbidden();
    }

    public function test_post_and_put_reject_clinical_and_financial_status_fields(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
            'clinical_status' => 'completed',
        ])->assertUnprocessable();

        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'clinical_status' => 'completed',
        ])->assertUnprocessable();

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'financial_status' => 'paid',
        ])->assertUnprocessable();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::PLANNED, $treatment->clinical_status);
        $this->assertSame(FinancialStatus::UNPAID, $treatment->financial_status);
    }

    public function test_all_skipped_never_completes_treatment(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [
                ['tooth_number' => '16', 'status' => 'skipped'],
                ['tooth_number' => '26', 'status' => 'skipped'],
            ],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::CANCELLED, $treatment->clinical_status);
        $this->assertTrue($treatment->auto_cancelled);
        $this->assertNotSame(PatientTreatmentStatus::COMPLETED, $treatment->clinical_status);
    }

    public function test_manual_cancellation_is_not_revived_by_tooth_changes(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'in_progress',
        ])->assertOk();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'cancelled',
            'cancellation_reason' => 'Patient moved abroad',
        ])->assertOk();

        $treatment->refresh();
        $this->assertFalse($treatment->auto_cancelled);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::CANCELLED, $treatment->clinical_status);
        $this->assertFalse($treatment->auto_cancelled);
    }

    public function test_reopening_skipped_tooth_after_all_skipped_cancellation_restores_derived_status(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [
                ['tooth_number' => '16', 'status' => 'skipped'],
                ['tooth_number' => '26', 'status' => 'skipped'],
            ],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::CANCELLED, $treatment->clinical_status);
        $this->assertTrue($treatment->auto_cancelled);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::IN_PROGRESS, $treatment->clinical_status);
        $this->assertFalse($treatment->auto_cancelled);
        $this->assertNull($treatment->cancelled_at);
        $this->assertNull($treatment->cancellation_reason);
    }

    public function test_session_completed_without_tooth_numbers_leaves_teeth_unchanged(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'completed',
        ])->assertCreated();

        $this->assertSame(
            TreatmentToothStatus::PLANNED,
            $treatment->teeth()->where('tooth_number', '16')->first()->status
        );
        $this->assertSame(
            TreatmentToothStatus::PLANNED,
            $treatment->teeth()->where('tooth_number', '26')->first()->status
        );
        $this->assertSame(PatientTreatmentStatus::PLANNED, $treatment->fresh()->clinical_status);
    }

    public function test_delete_blocked_when_tooth_done_or_session_completed(): void
    {
        $withDoneTooth = $this->createTreatment(['tooth_numbers' => ['16']]);
        $this->patchJson("/api/v1/patient-treatments/{$withDoneTooth->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'done']],
        ])->assertOk();

        $this->deleteJson("/api/v1/patient-treatments/{$withDoneTooth->id}")
            ->assertUnprocessable();

        $withCompletedSession = $this->createTreatment(['tooth_numbers' => ['16']]);
        $this->postJson("/api/v1/patient-treatments/{$withCompletedSession->id}/sessions", [
            'status' => 'completed',
            'tooth_numbers' => ['16'],
        ])->assertCreated();

        $this->deleteJson("/api/v1/patient-treatments/{$withCompletedSession->id}")
            ->assertUnprocessable();
    }

    public function test_price_change_writes_audit_log(): void
    {
        $treatment = $this->createTreatment();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 175]],
        ])->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::TREATMENT_TOOTH_PRICE_CHANGED)
            ->where('auditable_id', $treatment->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('16', $log->old_values['tooth_number'] ?? null);
        $this->assertSame('175.00', $log->new_values['price'] ?? null);
    }

    public function test_cannot_change_price_on_done_tooth(): void
    {
        $treatment = $this->createTreatment();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'done']],
        ])->assertOk();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 50]],
        ])->assertUnprocessable();
    }

    public function test_clinical_status_auto_in_progress_and_completed_from_teeth(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::IN_PROGRESS, $treatment->clinical_status);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [
                ['tooth_number' => '16', 'status' => 'done'],
                ['tooth_number' => '26', 'status' => 'skipped'],
            ],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::COMPLETED, $treatment->clinical_status);
        $this->assertNotNull($treatment->completed_at);
    }

    public function test_reopening_a_tooth_reopens_completed_treatment(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'done']],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::COMPLETED, $treatment->clinical_status);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::IN_PROGRESS, $treatment->clinical_status);
        $this->assertNull($treatment->completed_at);
    }

    public function test_manual_complete_rejected_with_unresolved_teeth(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'completed',
        ])->assertUnprocessable();
    }

    public function test_session_tooth_numbers_must_be_subset_of_treatment_teeth(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'scheduled',
            'tooth_numbers' => ['26'],
        ])->assertUnprocessable();
    }

    public function test_completed_session_promotes_planned_teeth_to_in_progress(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16', '26']]);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'completed',
            'tooth_numbers' => ['16'],
        ])->assertCreated();

        $this->assertSame(
            TreatmentToothStatus::IN_PROGRESS,
            $treatment->teeth()->where('tooth_number', '16')->first()->status
        );
        $this->assertSame(
            TreatmentToothStatus::PLANNED,
            $treatment->teeth()->where('tooth_number', '26')->first()->status
        );
    }

    public function test_template_delete_blocked_when_used(): void
    {
        $template = $this->createTemplate();
        $this->createTreatment(['treatment_template_id' => $template->id]);

        $this->deleteJson("/api/v1/treatment-templates/{$template->id}")
            ->assertUnprocessable();
    }

    public function test_template_list_filters_by_scope_active_and_search(): void
    {
        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'تنظيف',
            'name_en' => 'Prophylaxis Clean',
            'category' => 'preventive',
            'scope' => 'full_mouth',
            'default_price' => 300,
            'estimated_duration_minutes' => 60,
            'is_active' => false,
        ])->assertCreated();

        $this->getJson('/api/v1/treatment-templates?scope=tooth&active=1&search=Prophy')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/api/v1/treatment-templates?scope=full_mouth&active=0')
            ->assertOk()
            ->assertJsonPath('data.items.0.name_en', 'Prophylaxis Clean');
    }

    public function test_template_decimal_default_price_is_rejected(): void
    {
        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'x',
            'name_en' => 'Decimal Price',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 99.99,
            'estimated_duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['default_price']);
    }

    public function test_template_duration_must_be_between_5_and_120_minutes(): void
    {
        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'x',
            'name_en' => 'Too Short',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 100,
            'estimated_duration_minutes' => 4,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'x',
            'name_en' => 'Too Long',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 100,
            'estimated_duration_minutes' => 121,
        ])->assertUnprocessable();
    }

    public function test_decimal_tooth_price_is_rejected(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100, 'tooth_numbers' => ['16']]);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 125.5]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teeth.0.price']);
    }

    public function test_invoice_sync_updates_unpaid_invoice_total(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100, 'tooth_numbers' => ['16', '26']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 150]],
        ])->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame('250.00', (string) $invoice->fresh()->total_amount);
    }

    public function test_invoice_sync_blocked_when_partial_payments_exceed_new_total(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 200, 'tooth_numbers' => ['16']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 150,
            'method' => 'cash',
        ])->assertCreated();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 50]],
        ])->assertUnprocessable();
    }

    public function test_invoice_sync_allowed_on_partial_when_total_still_covers_payments(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 200, 'tooth_numbers' => ['16']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 150]],
        ])->assertOk();

        $this->assertSame(InvoiceStatus::PARTIAL, Invoice::query()->find($invoiceId)->status);
    }

    public function test_invoice_sync_blocked_when_invoice_is_paid(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100, 'tooth_numbers' => ['16']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 200]],
        ])->assertUnprocessable();
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

    public function test_odontogram_treatment_status_uses_per_tooth_in_progress_priority(): void
    {
        $template = $this->createTemplate();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated();

        $inProgress = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$inProgress}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonPath('data.teeth.16.state', 'in_progress')
            ->assertJsonPath('data.treatment_status_by_tooth.16', 'in_progress');
    }

    public function test_refund_does_not_change_treatment_status_price_or_notes(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100, 'tooth_numbers' => ['16']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;
        $notes = $treatment->notes;
        $clinical = $treatment->clinical_status;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame(FinancialStatus::PAID, $treatment->financial_status);

        $this->postJson("/api/v1/invoices/{$invoiceId}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame($clinical, $treatment->clinical_status);
        $this->assertSame(FinancialStatus::PAID, $treatment->financial_status);
        $this->assertEquals(100, (float) $treatment->agreed_price);
        $this->assertSame($notes, $treatment->notes);
        $this->assertSame(InvoiceStatus::REFUNDED, Invoice::query()->find($invoiceId)->status);
    }

    public function test_treatment_price_change_does_not_update_a_refunded_invoice(): void
    {
        $treatment = $this->createTreatment(['agreed_price' => 100, 'tooth_numbers' => ['16']]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoiceId}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/teeth", [
            'teeth' => [['tooth_number' => '16', 'price' => 200]],
        ])->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertSame('100.00', (string) $invoice->fresh()->total_amount);
        $this->assertSame('100.00', (string) $invoice->items()->first()->price);
        $this->assertEquals(200, (float) $treatment->fresh()->teeth()->first()->price);
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

    public function test_whole_mouth_treatment_is_created_without_teeth_and_bills_the_agreed_price(): void
    {
        $template = $this->createTemplate(TreatmentScope::FULL_MOUTH);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'agreed_price' => 250,
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'full_mouth')
            ->assertJsonPath('data.dentist_id', $this->user->id)
            ->assertJsonPath('data.agreed_price', 250)
            ->assertJsonCount(0, 'data.teeth');

        $treatment = PatientTreatment::query()->findOrFail($response->json('data.id'));
        $this->assertSame(0, $treatment->teeth()->count());

        $item = $treatment->invoiceItems()->first();
        $this->assertNotNull($item);
        $this->assertEquals(250.0, (float) $item->price * (int) $item->quantity);
        $this->assertEquals(250.0, (float) Invoice::query()->findOrFail($item->invoice_id)->total_amount);
    }

    public function test_whole_mouth_price_can_change_until_completion_and_stays_on_the_invoice(): void
    {
        $template = $this->createTemplate(TreatmentScope::FULL_MOUTH);
        $treatment = $this->createTreatment([
            'treatment_template_id' => $template->id,
            'agreed_price' => 250,
            'tooth_numbers' => [],
        ]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'agreed_price' => 180,
        ])->assertOk();

        $treatment->refresh();
        $this->assertEquals(180.0, (float) $treatment->agreed_price);
        $this->assertEquals(180.0, (float) $treatment->invoiceItems()->first()->price);
        $this->assertEquals(180.0, (float) Invoice::query()->findOrFail($invoiceId)->total_amount);

        $this->postJson("/api/v1/patient-treatments/{$treatment->id}/sessions", [
            'status' => 'completed',
        ])->assertCreated();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::IN_PROGRESS, $treatment->clinical_status);

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'agreed_price' => 190,
        ])->assertOk();

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'completed',
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::COMPLETED, $treatment->clinical_status);
        $this->assertEquals(190.0, (float) $treatment->agreed_price);
        $this->assertEquals(190.0, (float) Invoice::query()->findOrFail($invoiceId)->total_amount);
        $this->assertEquals(190.0, (float) $treatment->invoiceItems()->first()->price);

        $this->putJson("/api/v1/patient-treatments/{$treatment->id}", [
            'agreed_price' => 200,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['agreed_price']);

        $this->assertEquals(190.0, (float) $treatment->fresh()->agreed_price);
        $this->assertEquals(190.0, (float) Invoice::query()->findOrFail($invoiceId)->total_amount);
    }

    public function test_cancelling_a_whole_mouth_treatment_removes_its_unpaid_invoice(): void
    {
        $template = $this->createTemplate(TreatmentScope::FULL_MOUTH);
        $treatment = $this->createTreatment([
            'treatment_template_id' => $template->id,
            'agreed_price' => 250,
            'tooth_numbers' => [],
        ]);
        $invoiceId = $treatment->invoiceItems()->first()->invoice_id;

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'cancelled',
            'cancellation_reason' => 'Patient declined',
        ])->assertOk();

        $treatment->refresh();
        $this->assertSame(PatientTreatmentStatus::CANCELLED, $treatment->clinical_status);
        $this->assertNull(Invoice::query()->find($invoiceId));
        $trashed = Invoice::withTrashed()->find($invoiceId);
        $this->assertNotNull($trashed);
        $this->assertSame(InvoiceStatus::CANCELLED, $trashed->status);
    }

    public function test_receptionist_cannot_complete_a_whole_mouth_treatment(): void
    {
        $template = $this->createTemplate(TreatmentScope::FULL_MOUTH);
        $treatment = $this->createTreatment([
            'treatment_template_id' => $template->id,
            'tooth_numbers' => [],
        ]);

        $receptionist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($receptionist, ['*']);

        $this->patchJson("/api/v1/patient-treatments/{$treatment->id}/status", [
            'clinical_status' => 'completed',
        ])->assertForbidden();

        $this->assertSame(PatientTreatmentStatus::PLANNED, $treatment->fresh()->clinical_status);
    }

    public function test_changing_a_template_scope_does_not_change_existing_patient_treatments(): void
    {
        $treatment = $this->createTreatment(['tooth_numbers' => ['16']]);
        $template = $treatment->template;

        $this->putJson("/api/v1/treatment-templates/{$template->id}", [
            'name_ar' => $template->name_ar,
            'name_en' => $template->name_en,
            'category' => 'medical',
            'scope' => 'full_mouth',
            'default_price' => 250,
            'estimated_duration_minutes' => 30,
        ])->assertOk();

        $this->assertSame(TreatmentScope::FULL_MOUTH, $template->fresh()->scope);
        $this->assertSame(TreatmentScope::TOOTH, $treatment->fresh()->scope);
        $this->assertSame(1, $treatment->teeth()->count());
    }

    public function test_default_templates_keep_cleaning_as_full_mouth(): void
    {
        $templates = app(DefaultTreatmentTemplatesProvisioner::class)->provision();

        $this->assertSame(TreatmentScope::FULL_MOUTH, $templates['cleaning']->scope);
        $this->assertSame('Cleaning', $templates['cleaning']->name_en);
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
            'tooth_numbers' => ['16'],
        ], $overrides);

        /** @var User $actor */
        $actor = auth()->user();
        if (! $actor->hasAnyClinicRole([UserRole::DOCTOR, UserRole::OWNER])) {
            $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
            $appointment = Appointment::factory()->create([
                'tenant_id' => $this->tenant->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
            ]);
            $payload['appointment_id'] = $appointment->id;
        }

        $id = $this->postJson('/api/v1/patient-treatments', $payload)->assertCreated()->json('data.id');

        return PatientTreatment::query()->findOrFail($id);
    }

    private function createTemplate(TreatmentScope $scope = TreatmentScope::TOOTH): TreatmentTemplate
    {
        $id = $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'علاج '.uniqid(),
            'name_en' => 'Treatment '.uniqid(),
            'category' => 'medical',
            'scope' => $scope->value,
            'default_price' => 250,
            'estimated_duration_minutes' => 30,
        ])->assertCreated()->json('data.id');

        return TreatmentTemplate::query()->findOrFail($id);
    }
}

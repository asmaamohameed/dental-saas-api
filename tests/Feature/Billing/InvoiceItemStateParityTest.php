<?php

namespace Tests\Feature\Billing;

use App\Billing\Domain\InvoiceItemFacts;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\AppointmentStatus;
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
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Models\TreatmentTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceItemStateParityTest extends TestCase
{
    private Patient $patient;

    private User $doctor;

    private Invoice $invoice;

    private TreatmentTemplate $template;

    private ?PatientTreatment $toothTreatment = null;

    private int $toothNumber = 10;

    private int $hour = 0;

    public function test_php_resolver_and_sql_agree_for_each_line_kind(): void
    {
        $this->setUpClinic();
        $resolver = new InvoiceItemStateResolver;

        $cases = [
            'tooth planned' => ['planned', false, fn () => $this->toothItem(TreatmentToothStatus::PLANNED)],
            'tooth in_progress' => ['in_progress', false, fn () => $this->toothItem(TreatmentToothStatus::IN_PROGRESS)],
            'tooth done' => ['done', true, fn () => $this->toothItem(TreatmentToothStatus::DONE)],
            'tooth skipped' => ['skipped', false, fn () => $this->toothItem(TreatmentToothStatus::SKIPPED)],
            'tooth unknown status' => ['planned', false, fn () => $this->toothItemWithRawStatus('mystery')],
            'tooth voided' => ['voided', false, fn () => $this->voidItem($this->toothItem(TreatmentToothStatus::DONE))],
            'tooth deleted' => ['deleted', false, fn () => $this->deleteItem($this->toothItem(TreatmentToothStatus::PLANNED))],
            'tooth deleted after void' => ['deleted', false, fn () => $this->deleteItem($this->voidItem($this->toothItem(TreatmentToothStatus::DONE)))],
            'full-mouth planned' => ['planned', false, fn () => $this->fullMouthItem(PatientTreatmentStatus::PLANNED)],
            'full-mouth on_hold' => ['planned', false, fn () => $this->fullMouthItem(PatientTreatmentStatus::ON_HOLD)],
            'full-mouth in_progress' => ['in_progress', false, fn () => $this->fullMouthItem(PatientTreatmentStatus::IN_PROGRESS)],
            'full-mouth completed' => ['done', true, fn () => $this->fullMouthItem(PatientTreatmentStatus::COMPLETED)],
            'full-mouth failed' => ['done', true, fn () => $this->fullMouthItem(PatientTreatmentStatus::FAILED)],
            'full-mouth cancelled' => ['skipped', false, fn () => $this->fullMouthItem(PatientTreatmentStatus::CANCELLED)],
            'full-mouth unknown status' => ['planned', false, fn () => $this->fullMouthItemWithRawStatus('mystery')],
            'full-mouth voided' => ['voided', false, fn () => $this->voidItem($this->fullMouthItem(PatientTreatmentStatus::COMPLETED))],
            'full-mouth deleted' => ['deleted', false, fn () => $this->deleteItem($this->fullMouthItem(PatientTreatmentStatus::PLANNED))],
            'visit scheduled' => ['planned', false, fn () => $this->visitItem(AppointmentStatus::SCHEDULED, false)],
            'visit checked_in' => ['planned', false, fn () => $this->visitItem(AppointmentStatus::CHECKED_IN, false)],
            'visit in_progress' => ['planned', false, fn () => $this->visitItem(AppointmentStatus::IN_PROGRESS, false)],
            'visit completed' => ['done', true, fn () => $this->visitItem(AppointmentStatus::COMPLETED, true)],
            'visit cancelled before completion' => ['planned', false, fn () => $this->visitItem(AppointmentStatus::CANCELLED, false)],
            'visit no_show before completion' => ['planned', false, fn () => $this->visitItem(AppointmentStatus::NO_SHOW, false)],
            'visit cancelled after completed' => ['done', true, fn () => $this->visitItem(AppointmentStatus::CANCELLED, true)],
            'visit no_show after completed' => ['done', true, fn () => $this->visitItem(AppointmentStatus::NO_SHOW, true)],
            'visit voided' => ['voided', false, fn () => $this->voidItem($this->visitItem(AppointmentStatus::COMPLETED, true))],
            'visit deleted' => ['deleted', false, fn () => $this->deleteItem($this->visitItem(AppointmentStatus::CHECKED_IN, false))],
            'manual line' => ['planned', true, fn () => $this->manualItem()],
            'manual voided' => ['voided', false, fn () => $this->voidItem($this->manualItem())],
            'manual deleted' => ['deleted', false, fn () => $this->deleteItem($this->manualItem())],
        ];

        foreach ($cases as $name => [$expectedState, $expectedCounts, $make]) {
            $row = $this->readItem($make());
            $facts = $this->factsFrom($row);

            $this->assertSame($expectedState, $resolver->resolve($facts)->value, $name);
            $this->assertSame($expectedCounts, $resolver->countsAsPerformed($facts), $name.' counts');
            $expectedBillable = ! in_array($expectedState, ['deleted', 'voided', 'skipped'], true);
            $this->assertSame($expectedBillable, $resolver->billable($facts), $name.' billable');
            $this->assertSame($expectedState, (string) $row->state, $name.' sql');
            $this->assertSame($expectedCounts, (int) $row->counts_as_performed === 1, $name.' sql counts');
        }
    }

    public function test_cancel_and_no_show_after_completion_keep_completed_at_and_the_visit_stays_done(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $completedAt = Carbon::parse('2026-10-01 11:00:00', 'UTC');
        $resolver = new InvoiceItemStateResolver;

        foreach ([AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW] as $index => $target) {
            $appointment = Appointment::factory()->create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'created_by' => $owner->id,
                'scheduled_at' => Carbon::parse('2026-10-02 09:00:00', 'UTC')->addHours($index),
                'duration_minutes' => 30,
                'status' => AppointmentStatus::COMPLETED,
                'completed_at' => $completedAt,
            ]);
            $itemId = InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'appointment_id' => $appointment->id,
                'price' => 0,
                'quantity' => 1,
            ])->id;

            $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => $target->value,
            ])->assertOk();

            $appointment->refresh();
            $this->assertSame(
                '2026-10-01 11:00:00',
                $appointment->completed_at?->utc()->toDateTimeString(),
                $target->value
            );

            $row = $this->readItem($itemId);
            $facts = $this->factsFrom($row);
            $this->assertSame('done', $resolver->resolve($facts)->value, $target->value);
            $this->assertTrue($resolver->countsAsPerformed($facts), $target->value);
            $this->assertSame('done', (string) $row->state, $target->value);
            $this->assertSame(1, (int) $row->counts_as_performed, $target->value);
        }
    }

    private function setUpClinic(): void
    {
        $this->doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $this->patient = Patient::factory()->create();
        $this->invoice = Invoice::factory()->create(['patient_id' => $this->patient->id]);
        $this->template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
    }

    private function toothItem(TreatmentToothStatus $status): string
    {
        $this->toothTreatment ??= $this->makeTreatment(PatientTreatmentStatus::IN_PROGRESS, TreatmentScope::TOOTH);
        $this->toothNumber++;

        $tooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $this->toothTreatment->id,
            'tooth_number' => (string) $this->toothNumber,
            'status' => $status,
            'price' => 25,
        ]);

        return InvoiceItem::factory()->create([
            'invoice_id' => $this->invoice->id,
            'patient_treatment_id' => $this->toothTreatment->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 25,
            'quantity' => 1,
        ])->id;
    }

    private function toothItemWithRawStatus(string $status): string
    {
        $itemId = $this->toothItem(TreatmentToothStatus::PLANNED);
        $toothId = InvoiceItem::query()->findOrFail($itemId)->patient_treatment_tooth_id;
        DB::table('patient_treatment_teeth')->where('id', $toothId)->update(['status' => $status]);

        return $itemId;
    }

    private function fullMouthItem(PatientTreatmentStatus $status): string
    {
        $treatment = $this->makeTreatment($status, TreatmentScope::FULL_MOUTH);

        return InvoiceItem::factory()->create([
            'invoice_id' => $this->invoice->id,
            'patient_treatment_id' => $treatment->id,
            'price' => 80,
            'quantity' => 1,
        ])->id;
    }

    private function fullMouthItemWithRawStatus(string $status): string
    {
        $itemId = $this->fullMouthItem(PatientTreatmentStatus::PLANNED);
        $treatmentId = InvoiceItem::query()->findOrFail($itemId)->patient_treatment_id;
        DB::table('patient_treatments')->where('id', $treatmentId)->update(['clinical_status' => $status]);

        return $itemId;
    }

    private function visitItem(AppointmentStatus $status, bool $wasCompleted): string
    {
        $this->hour++;
        $appointment = Appointment::factory()->create([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => now()->addDays(3)->addHours($this->hour),
            'duration_minutes' => 30,
            'status' => $status,
            'completed_at' => $wasCompleted ? now()->subHour() : null,
        ]);

        return InvoiceItem::factory()->create([
            'invoice_id' => $this->invoice->id,
            'appointment_id' => $appointment->id,
            'price' => 15,
            'quantity' => 1,
        ])->id;
    }

    private function manualItem(): string
    {
        return InvoiceItem::factory()->create([
            'invoice_id' => $this->invoice->id,
            'description' => 'Manual',
            'price' => 12,
            'quantity' => 1,
        ])->id;
    }

    private function voidItem(string $itemId): string
    {
        DB::table('invoice_items')->where('id', $itemId)->update([
            'voided_at' => now(),
            'void_reason' => 'clinical failure',
            'voided_by' => $this->doctor->id,
        ]);

        return $itemId;
    }

    private function deleteItem(string $itemId): string
    {
        InvoiceItem::query()->findOrFail($itemId)->delete();

        return $itemId;
    }

    private function makeTreatment(PatientTreatmentStatus $status, TreatmentScope $scope): PatientTreatment
    {
        return PatientTreatment::query()->create([
            'patient_id' => $this->patient->id,
            'treatment_template_id' => $this->template->id,
            'scope' => $scope,
            'agreed_price' => 100,
            'clinical_status' => $status,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);
    }

    private function readItem(string $itemId): object
    {
        $sql = new InvoiceItemStateSql;
        $row = DB::selectOne(
            'select ii.deleted_at, ii.voided_at, ii.patient_treatment_tooth_id, tooth.status as tooth_status, '
            .'ii.patient_treatment_id, treatment.clinical_status, ii.appointment_id, '
            .'appointment.status as appointment_status, appointment.completed_at as appointment_completed_at, '
            .$sql->stateSql().' as state, '.$sql->countsAsPerformedSql().' as counts_as_performed '
            .$sql->fromSql().' where ii.id = ?',
            [$itemId]
        );
        $this->assertIsObject($row);

        return $row;
    }

    private function factsFrom(object $row): InvoiceItemFacts
    {
        $values = get_object_vars($row);

        return new InvoiceItemFacts(
            deleted: $values['deleted_at'] !== null,
            voided: $values['voided_at'] !== null,
            patientTreatmentToothId: $values['patient_treatment_tooth_id'] !== null ? (string) $values['patient_treatment_tooth_id'] : null,
            toothStatus: $values['tooth_status'] !== null ? (string) $values['tooth_status'] : null,
            patientTreatmentId: $values['patient_treatment_id'] !== null ? (string) $values['patient_treatment_id'] : null,
            clinicalStatus: $values['clinical_status'] !== null ? (string) $values['clinical_status'] : null,
            appointmentId: $values['appointment_id'] !== null ? (string) $values['appointment_id'] : null,
            appointmentStatus: $values['appointment_status'] !== null ? (string) $values['appointment_status'] : null,
            appointmentWasCompleted: $values['appointment_completed_at'] !== null,
        );
    }
}

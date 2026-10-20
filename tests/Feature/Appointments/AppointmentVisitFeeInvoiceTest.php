<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TreatmentCategory;
use App\Enums\TreatmentPlanStatus;
use App\Enums\TreatmentScope;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentTemplate;
use App\Models\User;
use Tests\TestCase;

class AppointmentVisitFeeInvoiceTest extends TestCase
{
    public function test_check_in_with_visit_fee_creates_one_invoice_line(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = $this->visitAppointment(['fee' => 200]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $this->assertSame(1, Invoice::query()->count());
        $invoice = Invoice::query()->firstOrFail();
        $this->assertNull($invoice->treatment_plan_id);
        $this->assertSame($appointment->id, $invoice->appointment_id);

        $item = InvoiceItem::query()->where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertSame('200.00', (string) $item->price);
        $this->assertSame('Consultation', $item->description);
        $this->assertSame(1, (int) $item->quantity);
    }

    public function test_repeat_check_in_and_doctor_change_at_check_in_keep_a_single_visit_line(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $replacement = User::factory()->doctor()->create();
        $appointment = $this->visitAppointment(['fee' => 120]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $replacement->id,
        ])->assertOk();

        $this->assertSame(1, InvoiceItem::query()->where('appointment_id', $appointment->id)->count());
    }

    public function test_zero_fee_check_in_creates_no_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = $this->visitAppointment(['fee' => 0]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceItem::query()->where('appointment_id', $appointment->id)->count());
    }

    public function test_cancel_before_completion_removes_visit_line_and_empty_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = $this->visitAppointment(['fee' => 80]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $invoiceId = Invoice::query()->value('id');
        $itemId = InvoiceItem::query()->where('appointment_id', $appointment->id)->value('id');
        $this->assertNotNull($invoiceId);
        $this->assertNotNull($itemId);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'no_show',
        ])->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $itemId]);
        $this->assertSoftDeleted('invoices', ['id' => $invoiceId]);
    }

    public function test_cancel_keeps_invoice_when_it_has_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = $this->visitAppointment(['fee' => 100]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('invoice_items', [
            'appointment_id' => $appointment->id,
            'deleted_at' => null,
        ]);
    }

    public function test_visit_invoice_is_separate_from_treatment_plan_invoice(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::query()->create([
            'patient_id' => $patient->id,
            'title' => 'Smile plan',
            'status' => TreatmentPlanStatus::ACTIVE,
        ]);
        $planInvoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $plan->id,
            'total_amount' => 500,
            'status' => InvoiceStatus::UNPAID,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $planInvoice->id,
            'description' => 'Plan deposit',
            'price' => 500,
            'quantity' => 1,
        ]);

        $appointment = $this->visitAppointment([
            'patient_id' => $patient->id,
            'fee' => 75,
            'created_by' => $owner->id,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $planInvoice->refresh();
        $this->assertSame('500.00', (string) $planInvoice->total_amount);
        $this->assertSame($plan->id, $planInvoice->treatment_plan_id);

        $visitInvoice = Invoice::query()
            ->where('appointment_id', $appointment->id)
            ->firstOrFail();
        $this->assertNull($visitInvoice->treatment_plan_id);
        $this->assertNotSame($planInvoice->id, $visitInvoice->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function visitAppointment(array $overrides = []): Appointment
    {
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create();
        $service = TreatmentTemplate::query()->create([
            'name_ar' => 'استشارة',
            'name_en' => 'Consultation',
            'category' => TreatmentCategory::VISIT,
            'scope' => TreatmentScope::FULL_MOUTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        return Appointment::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'created_by' => $doctor->id,
            'service_id' => $service->id,
            'fee' => 150,
            'status' => AppointmentStatus::SCHEDULED,
        ], $overrides));
    }
}

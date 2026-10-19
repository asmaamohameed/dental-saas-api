<?php

namespace Tests\Feature\Billing;

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
use App\Models\Refund;
use App\Models\TreatmentTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceItemModelColumnsTest extends TestCase
{
    public function test_fresh_item_has_null_voided_at_and_zero_refunded_amount(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $item = InvoiceItem::factory()->create();

        $this->assertNull($item->voided_at);
        $this->assertSame(0.0, $item->refundedAmount());
    }

    public function test_voided_at_casts_to_carbon(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $item = InvoiceItem::factory()->create([
            'voided_at' => '2026-01-15 10:30:00',
            'void_reason' => 'duplicate line',
            'voided_by' => $user->id,
        ]);

        $this->assertInstanceOf(Carbon::class, $item->voided_at);
        $this->assertSame('2026-01-15 10:30:00', $item->voided_at->format('Y-m-d H:i:s'));
    }

    public function test_tooth_appointment_and_voided_by_relations_resolve(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $invoice->patient_id]);
        $tooth = $this->createTreatmentTooth();
        $voidedBy = User::factory()->create(['tenant_id' => $user->tenant_id]);

        $itemWithTooth = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'voided_at' => now(),
            'void_reason' => 'test',
            'voided_by' => $voidedBy->id,
        ]);
        $itemWithTooth->load(['tooth', 'voidedByUser']);

        $this->assertTrue($itemWithTooth->tooth->is($tooth));
        $this->assertTrue($itemWithTooth->voidedByUser->is($voidedBy));

        $itemWithVisit = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'appointment_id' => $appointment->id,
        ]);
        $itemWithVisit->load('appointment');

        $this->assertTrue($itemWithVisit->appointment->is($appointment));
    }

    public function test_refunds_relation_and_refunded_amount_sum_refund_rows(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $item = InvoiceItem::factory()->create();

        Refund::factory()->create([
            'invoice_id' => $item->invoice_id,
            'invoice_item_id' => $item->id,
            'amount' => 25,
        ]);
        Refund::factory()->create([
            'invoice_id' => $item->invoice_id,
            'invoice_item_id' => $item->id,
            'amount' => 10.50,
        ]);

        $this->assertCount(2, $item->refunds);
        $this->assertEquals(35.5, $item->refundedAmount());

        $loaded = $item->fresh()->load('refunds');
        $this->assertEquals(35.5, $loaded->refundedAmount());
    }

    private function createTreatmentTooth(): PatientTreatmentTooth
    {
        $patient = Patient::factory()->create();
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'حشو',
            'name_en' => 'Filling',
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
            'clinical_status' => PatientTreatmentStatus::IN_PROGRESS,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);

        return PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '11',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => 100,
        ]);
    }
}

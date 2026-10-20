<?php

namespace Tests\Feature\Billing;

use App\Billing\Domain\InvoiceBalance;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentToothStatus;
use App\Enums\TreatmentType;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Models\Payment;
use App\Models\TreatmentTemplate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceBalanceTest extends TestCase
{
    public function test_void_after_cash_and_deduct_sets_refund_due_without_remaining(): void
    {
        // total 1000, cash 600, deduct 400, then a 300 void leaves 700 billable.
        $balance = InvoiceBalance::fromRawSums('600', '0', '400', '700', '400');

        $this->assertSame('600.00', $balance->netPaid);
        $this->assertSame('1000.00', $balance->settled);
        $this->assertSame('0.00', $balance->remaining);
        $this->assertSame('300.00', $balance->refundDue);
        $this->assertTrue($balance->hasRefundDue);
        $this->assertSame(InvoiceStatus::PAID, $balance->status());
    }

    public function test_write_off_without_cash_has_no_refund_due(): void
    {
        $covered = InvoiceBalance::fromRawSums('0', '0', '1000', '1000', '0');
        $voidedAfterWriteOff = InvoiceBalance::fromRawSums('0', '0', '400', '0', '0');

        $this->assertSame('0.00', $covered->refundDue);
        $this->assertFalse($covered->hasRefundDue);
        $this->assertSame('0.00', $covered->remaining);
        $this->assertSame(InvoiceStatus::PAID, $covered->status());

        $this->assertSame('0.00', $voidedAfterWriteOff->netPaid);
        $this->assertSame('0.00', $voidedAfterWriteOff->refundDue);
        $this->assertFalse($voidedAfterWriteOff->hasRefundDue);
        $this->assertSame('0.00', $voidedAfterWriteOff->remaining);
    }

    public function test_refund_due_stays_non_negative_and_never_exceeds_net_paid(): void
    {
        $overRefunded = InvoiceBalance::fromRawSums('100', '250', '0', '100', '100');
        $this->assertSame('0.00', $overRefunded->netPaid);
        $this->assertSame('0.00', $overRefunded->refundDue);

        $grossValues = ['0', '100', '600'];
        $refundValues = ['0', '50', '600'];
        $deductValues = ['0', '400'];
        $totals = ['0', '700', '1000'];
        $performedValues = ['0', '400'];

        foreach ($grossValues as $gross) {
            foreach ($refundValues as $refunded) {
                foreach ($deductValues as $deducted) {
                    foreach ($totals as $total) {
                        foreach ($performedValues as $performed) {
                            $balance = InvoiceBalance::fromRawSums($gross, $refunded, $deducted, $total, $performed);
                            $label = "gross {$gross} refunded {$refunded} deduct {$deducted} total {$total} performed {$performed}";

                            $this->assertTrue(bccomp($balance->refundDue, '0', 2) >= 0, $label);
                            $this->assertTrue(bccomp($balance->remaining, '0', 2) >= 0, $label);
                            $this->assertTrue(bccomp($balance->netPaid, '0', 2) >= 0, $label);
                            $this->assertTrue(bccomp($balance->refundDue, $balance->netPaid, 2) <= 0, $label);
                            $this->assertTrue(bccomp((string) $balance->duePerformed, '0', 2) >= 0, $label);
                            $this->assertFalse(
                                bccomp($balance->remaining, '0', 2) === 1 && bccomp($balance->refundDue, '0', 2) === 1,
                                $label
                            );
                        }
                    }
                }
            }
        }
    }

    public function test_prepayment_is_applied_to_performed_work_first(): void
    {
        $covered = InvoiceBalance::fromRawSums('600', '0', '0', '1000', '400');
        $this->assertSame('0.00', $covered->duePerformed);
        $this->assertSame('400.00', $covered->remaining);

        $stillDue = InvoiceBalance::fromRawSums('200', '0', '0', '1000', '800');
        $this->assertSame('600.00', $stillDue->duePerformed);
        $this->assertSame('800.00', $stillDue->remaining);
        $this->assertSame('0.00', $stillDue->refundDue);
    }

    public function test_status_precedence_puts_refunded_before_zero_total_paid(): void
    {
        $this->assertSame(InvoiceStatus::PAID, InvoiceBalance::fromRawSums('0', '0', '0', '0')->status());
        $this->assertSame(InvoiceStatus::REFUNDED, InvoiceBalance::fromRawSums('40', '40', '0', '0')->status());
        $this->assertSame(InvoiceStatus::PAID, InvoiceBalance::fromRawSums('600', '0', '400', '700')->status());
        $this->assertSame(InvoiceStatus::PARTIAL, InvoiceBalance::fromRawSums('10', '0', '0', '100')->status());
        $this->assertSame(InvoiceStatus::UNPAID, InvoiceBalance::fromRawSums('0', '0', '0', '100')->status());
        $this->assertSame(InvoiceStatus::UNPAID, InvoiceBalance::fromRawSums('100', '100', '0', '100')->status());
    }

    public function test_performed_totals_are_omitted_until_they_are_supplied(): void
    {
        $balance = InvoiceBalance::fromRawSums('10', '0', '0', '10');

        $this->assertNull($balance->performedTotal);
        $this->assertNull($balance->duePerformed);
        $this->assertArrayNotHasKey('performed_total', $balance->toArray());
        $this->assertArrayNotHasKey('due_performed', $balance->toArray());
    }

    public function test_default_balance_uses_the_stored_total_and_does_not_query_items(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 999]);
        $this->seedDerivedLines($invoice);

        $loaded = Invoice::query()
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('payments as deducted_amount', 'deduct_amount')
            ->withSum('refunds as refunded_amount', 'amount')
            ->findOrFail($invoice->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plain = $loaded->balance();
        $plainAgain = $loaded->balance();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($plain, $plainAgain);
        $this->assertSame([], $queries);
        $this->assertSame('999.00', $plain['total']);
        $this->assertSame('949.00', $plain['remaining']);
        $this->assertArrayNotHasKey('performed_total', $plain);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $derived = $loaded->balance(true);
        $derivedAgain = $loaded->balance(true);
        $derivedQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $derivedQueries);
        $this->assertStringContainsString('invoice_items', strtolower($derivedQueries[0]['query']));
        $this->assertSame($derived, $derivedAgain);
        $this->assertSame('170.00', $derived['total']);
        $this->assertSame('70.00', $derived['performed_total']);
        $this->assertSame('20.00', $derived['due_performed']);
        $this->assertSame('120.00', $derived['remaining']);
        $this->assertSame('949.00', $loaded->balance()['remaining']);
    }

    public function test_refresh_clears_the_balance_cache(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 25]);

        $loaded = Invoice::query()
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('payments as deducted_amount', 'deduct_amount')
            ->withSum('refunds as refunded_amount', 'amount')
            ->findOrFail($invoice->id);

        $this->assertSame('75.00', $loaded->balance()['remaining']);

        DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => 40]);

        $this->assertSame('75.00', $loaded->balance()['remaining']);

        $loaded->refresh();

        $this->assertSame('15.00', $loaded->balance()['remaining']);
    }

    private function seedDerivedLines(Invoice $invoice): void
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

        $this->toothLine($invoice, $treatment, '11', TreatmentToothStatus::PLANNED, 100);
        $this->toothLine($invoice, $treatment, '12', TreatmentToothStatus::SKIPPED, 50);
        $this->toothLine($invoice, $treatment, '13', TreatmentToothStatus::DONE, 30);
        $voided = $this->toothLine($invoice, $treatment, '14', TreatmentToothStatus::DONE, 15);
        $userId = (string) auth()->id();
        DB::table('invoice_items')->where('id', $voided->id)->update([
            'voided_at' => now(),
            'void_reason' => 'retreat',
            'voided_by' => $userId,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Manual fee',
            'price' => 20,
            'quantity' => 2,
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 50,
        ]);
    }

    private function toothLine(
        Invoice $invoice,
        PatientTreatment $treatment,
        string $toothNumber,
        TreatmentToothStatus $status,
        int $price,
    ): InvoiceItem {
        $tooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => $toothNumber,
            'status' => $status,
            'price' => $price,
        ]);

        return InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => $price,
            'quantity' => 1,
        ]);
    }
}

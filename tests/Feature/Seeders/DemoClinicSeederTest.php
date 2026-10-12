<?php

namespace Tests\Feature\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentToothStatus;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoClinicSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_clinic_seeder_satisfies_data_invariants(): void
    {
        $this->seed(DemoClinicSeeder::class);

        $tenant = Tenant::query()->where('subdomain', DemoClinicSeeder::SUBDOMAIN)->firstOrFail();
        app(CurrentTenant::class)->set($tenant->id);

        $this->assertToothCompletedAtRules();
        $this->assertTotalPriceMatchesTeeth();
        $this->assertClinicalStatusMatchesTeeth();
        $this->assertTreatmentInvoiceItemPrices();
        $this->assertInvoicePaymentConsistency();
        $this->assertSessionToothSubsetRules();
        $this->assertInventoryQuantities();
        $this->assertAppointmentStatusTimestamps();

        app(CurrentTenant::class)->clear();
    }

    public function test_demo_clinic_seeder_is_idempotent(): void
    {
        $this->seed(DemoClinicSeeder::class);

        $tenantId = Tenant::query()->where('subdomain', DemoClinicSeeder::SUBDOMAIN)->value('id');
        app(CurrentTenant::class)->set($tenantId);

        $patientsBefore = Patient::query()->count();
        $treatmentsBefore = PatientTreatment::query()->count();
        $invoicesBefore = Invoice::query()->count();
        $paymentsBefore = Payment::query()->count();

        $this->seed(DemoClinicSeeder::class);
        app(CurrentTenant::class)->set($tenantId);

        $this->assertSame($patientsBefore, Patient::query()->count());
        $this->assertSame($treatmentsBefore, PatientTreatment::query()->count());
        $this->assertSame($invoicesBefore, Invoice::query()->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());

        app(CurrentTenant::class)->clear();
    }

    private function assertToothCompletedAtRules(): void
    {
        foreach (PatientTreatment::query()->with('teeth')->get() as $treatment) {
            foreach ($treatment->teeth as $tooth) {
                if ($tooth->status === TreatmentToothStatus::DONE) {
                    $this->assertNotNull(
                        $tooth->completed_at,
                        "Tooth {$tooth->tooth_number} on treatment {$treatment->id} is done but missing completed_at."
                    );
                } else {
                    $this->assertNull(
                        $tooth->completed_at,
                        "Tooth {$tooth->tooth_number} on treatment {$treatment->id} is not done but has completed_at."
                    );
                }
            }
        }
    }

    private function assertTotalPriceMatchesTeeth(): void
    {
        foreach (PatientTreatment::query()->with('teeth')->get() as $treatment) {
            $expected = $treatment->scope === TreatmentScope::TOOTH
                ? (float) $treatment->teeth
                    ->reject(fn ($t) => $t->status === TreatmentToothStatus::SKIPPED)
                    ->sum(fn ($t) => (float) $t->price)
                : (float) $treatment->agreed_price;

            $this->assertEqualsWithDelta(
                $expected,
                $treatment->total_price,
                0.01,
                "total_price mismatch for treatment {$treatment->id}"
            );
        }
    }

    private function assertClinicalStatusMatchesTeeth(): void
    {
        foreach (PatientTreatment::query()->with('teeth')->get() as $treatment) {
            if ($treatment->clinical_status === PatientTreatmentStatus::CANCELLED) {
                continue;
            }

            if ($treatment->scope !== TreatmentScope::TOOTH || $treatment->teeth->isEmpty()) {
                continue;
            }

            $hasOpen = $treatment->teeth->contains(
                fn ($t) => in_array($t->status, [TreatmentToothStatus::PLANNED, TreatmentToothStatus::IN_PROGRESS], true)
            );
            $allResolved = $treatment->teeth->every(
                fn ($t) => in_array($t->status, [TreatmentToothStatus::DONE, TreatmentToothStatus::SKIPPED], true)
            );
            $anyDone = $treatment->teeth->contains(fn ($t) => $t->status === TreatmentToothStatus::DONE);

            if ($treatment->clinical_status === PatientTreatmentStatus::COMPLETED) {
                $this->assertFalse($hasOpen, "Completed treatment {$treatment->id} still has open teeth.");
                $this->assertTrue($anyDone, "Completed treatment {$treatment->id} has no done teeth.");
            }

            if ($allResolved && $anyDone) {
                $this->assertSame(
                    PatientTreatmentStatus::COMPLETED,
                    $treatment->clinical_status,
                    "Treatment {$treatment->id} should be completed from tooth state."
                );
            }

            if ($hasOpen && $treatment->clinical_status === PatientTreatmentStatus::COMPLETED) {
                $this->fail("Treatment {$treatment->id} is completed but has planned/in_progress teeth.");
            }
        }
    }

    private function assertTreatmentInvoiceItemPrices(): void
    {
        $items = InvoiceItem::query()
            ->whereNotNull('patient_treatment_id')
            ->with('patientTreatment.teeth')
            ->get();

        $this->assertNotEmpty($items, 'Expected at least one treatment-linked invoice item from the demo seeder.');

        foreach ($items as $item) {
            $treatment = $item->patientTreatment;
            $this->assertNotNull($treatment);
            $this->assertEqualsWithDelta(
                $treatment->total_price,
                (float) $item->price,
                0.01,
                "Invoice item {$item->id} price should match treatment total_price."
            );
        }
    }

    private function assertInvoicePaymentConsistency(): void
    {
        foreach (Invoice::query()->with('payments')->get() as $invoice) {
            $paid = (float) $invoice->payments->sum(fn ($p) => (float) $p->amount + (float) ($p->deduct_amount ?? 0));
            $total = (float) $invoice->total_amount;

            $this->assertLessThanOrEqual($total + 0.001, $paid, "Invoice {$invoice->id} payments exceed total.");

            $expectedStatus = match (true) {
                $total <= 0 && $paid <= 0 => InvoiceStatus::UNPAID,
                $paid <= 0 => InvoiceStatus::UNPAID,
                $paid >= $total - 0.001 => InvoiceStatus::PAID,
                default => InvoiceStatus::PARTIAL,
            };

            $this->assertSame(
                $expectedStatus,
                $invoice->status,
                "Invoice {$invoice->id} status does not match payments sum."
            );
        }
    }

    private function assertSessionToothSubsetRules(): void
    {
        foreach (PatientTreatment::query()->with(['teeth', 'sessions'])->get() as $treatment) {
            if ($treatment->scope !== TreatmentScope::TOOTH) {
                $this->assertCount(0, $treatment->teeth, 'Non-tooth scope treatments must not have teeth rows.');
            }

            $allowed = $treatment->teeth->pluck('tooth_number')->map(fn ($n) => (string) $n)->all();

            foreach ($treatment->sessions as $session) {
                $numbers = $session->tooth_numbers ?? [];
                foreach ($numbers as $number) {
                    $this->assertContains(
                        (string) $number,
                        $allowed,
                        "Session {$session->id} references tooth {$number} outside the treatment."
                    );
                }
            }
        }
    }

    private function assertInventoryQuantities(): void
    {
        foreach (InventoryItem::query()->with('transactions')->get() as $item) {
            $expected = $item->transactions->reduce(function (float $carry, InventoryTransaction $tx) {
                $qty = (float) $tx->quantity;
                $type = $tx->type instanceof InventoryTransactionType ? $tx->type : InventoryTransactionType::from((string) $tx->type);

                return $type === InventoryTransactionType::IN ? $carry + $qty : $carry - $qty;
            }, 0.0);

            $this->assertEqualsWithDelta(
                $expected,
                (float) $item->current_quantity,
                0.01,
                "Inventory item {$item->id} current_quantity does not match transactions."
            );
        }
    }

    private function assertAppointmentStatusTimestamps(): void
    {
        $appointments = Appointment::query()
            ->where('notes', 'like', '[demo-clinic]%')
            ->get();

        $this->assertNotEmpty($appointments);

        foreach ($appointments as $appointment) {
            if ($appointment->status === AppointmentStatus::CHECKED_IN) {
                $this->assertNotNull($appointment->checked_in_at);
            }

            if ($appointment->status === AppointmentStatus::IN_PROGRESS) {
                $this->assertNotNull($appointment->started_at);
            }

            if ($appointment->status === AppointmentStatus::COMPLETED) {
                $this->assertNotNull($appointment->completed_at);
            }
        }
    }
}

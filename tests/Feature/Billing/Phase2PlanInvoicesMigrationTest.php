<?php

namespace Tests\Feature\Billing;

use App\Enums\AppointmentStatus;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPlanStatus;
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
use App\Models\TreatmentPlan;
use App\Models\TreatmentTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2PlanInvoicesMigrationTest extends TestCase
{
    public function test_partial_indexes_checks_and_restrict_foreign_keys_exist(): void
    {
        $plan = $this->indexDef('invoices_one_active_plan_uidx');
        $this->assertStringContainsString('create unique index', $plan);
        $this->assertStringContainsString('(tenant_id, treatment_plan_id)', $plan);
        $this->assertStringContainsString('treatment_plan_id is not null', $plan);
        $this->assertStringContainsString('deleted_at is null', $plan);
        $this->assertStringNotContainsString('voided_at', $plan);

        $tooth = $this->indexDef('invoice_items_one_active_tooth_uidx');
        $this->assertStringContainsString('create unique index', $tooth);
        $this->assertStringContainsString('(patient_treatment_tooth_id)', $tooth);
        $this->assertStringContainsString('patient_treatment_tooth_id is not null', $tooth);
        $this->assertStringContainsString('deleted_at is null', $tooth);
        $this->assertStringContainsString('voided_at is null', $tooth);

        $visit = $this->indexDef('invoice_items_one_active_appointment_uidx');
        $this->assertStringContainsString('create unique index', $visit);
        $this->assertStringContainsString('(appointment_id)', $visit);
        $this->assertStringContainsString('appointment_id is not null', $visit);
        $this->assertStringContainsString('deleted_at is null', $visit);
        $this->assertStringNotContainsString('voided_at', $visit);

        $voidCheck = $this->checkDef('invoice_items_void_columns_check');
        $this->assertStringContainsString('voided_at is null', $voidCheck);
        $this->assertStringContainsString('void_reason is null', $voidCheck);
        $this->assertStringContainsString('voided_by is null', $voidCheck);
        $this->assertStringContainsString('voided_at is not null', $voidCheck);
        $this->assertStringContainsString('void_reason is not null', $voidCheck);
        $this->assertStringContainsString('voided_by is not null', $voidCheck);

        $exclusive = $this->checkDef('invoice_items_tooth_appointment_exclusive_check');
        $this->assertStringContainsString('patient_treatment_tooth_id', $exclusive);
        $this->assertStringContainsString('appointment_id', $exclusive);
        $this->assertStringContainsString('not', $exclusive);

        $visitCheck = $this->checkDef('invoice_items_visit_excludes_treatment_check');
        $this->assertStringContainsString('appointment_id', $visitCheck);
        $this->assertStringContainsString('patient_treatment_id', $visitCheck);

        foreach ([
            'invoices_treatment_plan_id_foreign',
            'appointments_treatment_plan_id_foreign',
            'invoice_items_patient_treatment_tooth_id_foreign',
            'invoice_items_appointment_id_foreign',
            'invoice_items_voided_by_foreign',
        ] as $foreignKey) {
            $this->assertSame('r', $this->foreignDeleteAction($foreignKey), $foreignKey);
        }

        $reason = DB::selectOne("
            select data_type
            from information_schema.columns
            where table_name = 'invoice_items' and column_name = 'void_reason'
        ");
        $this->assertNotNull($reason);
        $this->assertSame('text', $reason->data_type);
    }

    public function test_partial_unique_indexes_reject_live_duplicates_and_allow_the_documented_gaps(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $plan = $this->plan($patient);

        $first = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $plan->id,
        ]);
        $this->assertRejected('23505', 'invoices_one_active_plan_uidx', function () use ($patient, $plan): void {
            Invoice::factory()->create([
                'patient_id' => $patient->id,
                'treatment_plan_id' => $plan->id,
            ]);
        });

        $first->delete();
        Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $plan->id,
        ]);
        Invoice::factory()->create(['patient_id' => $patient->id, 'treatment_plan_id' => null]);
        Invoice::factory()->create(['patient_id' => $patient->id, 'treatment_plan_id' => null]);

        $otherPlan = $this->plan($patient);
        Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $otherPlan->id,
        ]);

        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $tooth = $this->tooth($patient);
        $liveTooth = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $tooth->patient_treatment_id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->assertRejected('23505', 'invoice_items_one_active_tooth_uidx', function () use ($invoice, $tooth): void {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'patient_treatment_id' => $tooth->patient_treatment_id,
                'patient_treatment_tooth_id' => $tooth->id,
                'price' => 10,
                'quantity' => 1,
            ]);
        });

        DB::table('invoice_items')->where('id', $liveTooth->id)->update([
            'voided_at' => now(),
            'void_reason' => 'retreat',
            'voided_by' => $user->id,
        ]);
        $retreat = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $tooth->patient_treatment_id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 12,
            'quantity' => 1,
        ]);
        $retreat->delete();
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $tooth->patient_treatment_id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 12,
            'quantity' => 1,
        ]);

        $appointment = $this->appointment($patient, $user);
        $visit = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'appointment_id' => $appointment->id,
            'price' => 0,
            'quantity' => 1,
        ]);
        $this->assertRejected('23505', 'invoice_items_one_active_appointment_uidx', function () use ($invoice, $appointment): void {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'appointment_id' => $appointment->id,
                'price' => 0,
                'quantity' => 1,
            ]);
        });

        DB::table('invoice_items')->where('id', $visit->id)->update([
            'voided_at' => now(),
            'void_reason' => 'duplicate visit',
            'voided_by' => $user->id,
        ]);
        $this->assertRejected('23505', 'invoice_items_one_active_appointment_uidx', function () use ($invoice, $appointment): void {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'appointment_id' => $appointment->id,
                'price' => 0,
                'quantity' => 1,
            ]);
        });

        $visit->delete();
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'appointment_id' => $appointment->id,
            'price' => 0,
            'quantity' => 1,
        ]);
    }

    public function test_check_constraints_reject_illegal_item_shapes(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $tooth = $this->tooth($patient);
        $appointment = $this->appointment($patient, $user);
        $treatment = PatientTreatment::query()->findOrFail($tooth->patient_treatment_id);

        $this->assertRejected('23514', 'invoice_items_tooth_appointment_exclusive_check', function () use ($invoice, $tooth, $appointment): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'patient_treatment_tooth_id' => $tooth->id,
                'appointment_id' => $appointment->id,
            ]));
        });

        $this->assertRejected('23514', 'invoice_items_visit_excludes_treatment_check', function () use ($invoice, $appointment, $treatment): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'appointment_id' => $appointment->id,
                'patient_treatment_id' => $treatment->id,
            ]));
        });

        $this->assertRejected('23514', 'invoice_items_void_columns_check', function () use ($invoice): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'voided_at' => now(),
            ]));
        });

        $this->assertRejected('23514', 'invoice_items_void_columns_check', function () use ($invoice): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'void_reason' => 'missing timestamp',
            ]));
        });

        $this->assertRejected('23514', 'invoice_items_void_columns_check', function () use ($invoice, $user): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'voided_by' => $user->id,
            ]));
        });

        $this->assertRejected('23514', 'invoice_items_void_columns_check', function () use ($invoice): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'voided_at' => now(),
                'void_reason' => 'missing user',
            ]));
        });

        DB::table('invoice_items')->insert($this->itemRow($invoice, [
            'voided_at' => now(),
            'void_reason' => 'complete void',
            'voided_by' => $user->id,
        ]));
        DB::table('invoice_items')->insert($this->itemRow($invoice, [
            'patient_treatment_id' => $treatment->id,
        ]));
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_foreign_keys_reject_missing_parents_and_restrict_deletes(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $plan = $this->plan($patient);
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $plan->id,
        ]);
        $tooth = $this->tooth($patient);
        $appointment = $this->appointment($patient, $user);
        $voidUser = User::factory()->receptionist()->create();

        $this->assertRejected('23503', 'invoices_treatment_plan_id_foreign', function () use ($patient): void {
            Invoice::factory()->create([
                'patient_id' => $patient->id,
                'treatment_plan_id' => (string) Str::uuid(),
            ]);
        });
        $this->assertRejected('23503', 'invoices_treatment_plan_id_foreign', function () use ($plan): void {
            $plan->delete();
        });

        $appointmentPlan = $this->plan($patient);
        $appointment->treatment_plan_id = $appointmentPlan->id;
        $appointment->save();
        $this->assertRejected('23503', 'appointments_treatment_plan_id_foreign', function () use ($appointmentPlan): void {
            DB::table('treatment_plans')->where('id', $appointmentPlan->id)->delete();
        });

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $tooth->patient_treatment_id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->assertRejected('23503', 'invoice_items_patient_treatment_tooth_id_foreign', function () use ($invoice): void {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'patient_treatment_tooth_id' => (string) Str::uuid(),
                'price' => 10,
                'quantity' => 1,
            ]);
        });
        $this->assertRejected('23503', 'invoice_items_patient_treatment_tooth_id_foreign', function () use ($tooth): void {
            $tooth->delete();
        });

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'appointment_id' => $appointment->id,
            'price' => 0,
            'quantity' => 1,
        ]);
        $this->assertRejected('23503', 'invoice_items_appointment_id_foreign', function () use ($invoice): void {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'appointment_id' => (string) Str::uuid(),
                'price' => 0,
                'quantity' => 1,
            ]);
        });
        $this->assertRejected('23503', 'invoice_items_appointment_id_foreign', function () use ($appointment): void {
            $appointment->delete();
        });

        DB::table('invoice_items')->insert($this->itemRow($invoice, [
            'voided_at' => now(),
            'void_reason' => 'audit',
            'voided_by' => $voidUser->id,
        ]));
        $this->assertRejected('23503', 'invoice_items_voided_by_foreign', function () use ($invoice): void {
            DB::table('invoice_items')->insert($this->itemRow($invoice, [
                'voided_at' => now(),
                'void_reason' => 'missing user',
                'voided_by' => (string) Str::uuid(),
            ]));
        });
        $this->assertRejected('23503', 'invoice_items_voided_by_foreign', function () use ($voidUser): void {
            $voidUser->delete();
        });
    }

    public function test_down_drops_only_what_up_added(): void
    {
        $this->assertTrue(Schema::hasColumn('invoices', 'treatment_plan_id'));
        $this->assertTrue(Schema::hasColumn('invoices', 'appointment_id'));
        $this->assertTrue(Schema::hasColumn('invoice_items', 'patient_treatment_id'));

        $migration = require database_path('migrations/2026_10_18_200000_phase2_plan_invoices.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('invoices', 'treatment_plan_id'));
        $this->assertFalse(Schema::hasColumn('appointments', 'treatment_plan_id'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'patient_treatment_tooth_id'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'appointment_id'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'voided_at'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'void_reason'));
        $this->assertFalse(Schema::hasColumn('invoice_items', 'voided_by'));
        $this->assertTrue(Schema::hasColumn('invoices', 'appointment_id'));
        $this->assertTrue(Schema::hasColumn('invoices', 'patient_id'));
        $this->assertTrue(Schema::hasColumn('invoice_items', 'patient_treatment_id'));
        $this->assertTrue(Schema::hasColumn('invoice_items', 'deleted_at'));
        $this->assertNull(DB::selectOne("select 1 from pg_indexes where indexname = 'invoices_one_active_plan_uidx'"));
        $this->assertNull(DB::selectOne("select 1 from pg_constraint where conname = 'invoice_items_void_columns_check'"));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('invoices', 'treatment_plan_id'));
        $this->assertNotNull($this->indexDef('invoice_items_one_active_tooth_uidx'));
        $this->assertNotNull($this->checkDef('invoice_items_visit_excludes_treatment_check'));
    }

    private function plan(Patient $patient): TreatmentPlan
    {
        return TreatmentPlan::query()->create([
            'patient_id' => $patient->id,
            'title' => 'Plan '.Str::uuid(),
            'status' => TreatmentPlanStatus::ACTIVE,
        ]);
    }

    private function tooth(Patient $patient): PatientTreatmentTooth
    {
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'حشو',
            'name_en' => 'Filling '.Str::uuid(),
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

        return PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '16',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => 100,
        ]);
    }

    private function appointment(Patient $patient, User $user): Appointment
    {
        return Appointment::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $user->id,
            'created_by' => $user->id,
            'scheduled_at' => now()->addDays(4),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::CHECKED_IN,
            'checked_in_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemRow(Invoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'description' => 'line',
            'price' => 10,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function indexDef(string $name): string
    {
        $row = DB::selectOne('select indexdef from pg_indexes where indexname = ?', [$name]);
        $this->assertNotNull($row, $name);

        return strtolower((string) $row->indexdef);
    }

    private function checkDef(string $name): string
    {
        $row = DB::selectOne(
            "select pg_get_constraintdef(oid) as definition, convalidated::int as valid
             from pg_constraint
             where conname = ? and contype = 'c'",
            [$name]
        );
        $this->assertNotNull($row, $name);
        $this->assertSame(1, (int) $row->valid, $name);

        return strtolower((string) $row->definition);
    }

    private function foreignDeleteAction(string $name): string
    {
        $row = DB::selectOne(
            "select confdeltype from pg_constraint where conname = ? and contype = 'f'",
            [$name]
        );
        $this->assertNotNull($row, $name);

        return (string) $row->confdeltype;
    }

    private function assertRejected(string $sqlState, string $constraint, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail("Expected {$constraint} to reject the write.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null, $exception->getMessage());
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}

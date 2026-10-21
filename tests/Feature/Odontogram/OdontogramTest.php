<?php

namespace Tests\Feature\Odontogram;

use App\Enums\InvoiceStatus;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentToothStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OdontogramTest extends TestCase
{
    private Tenant $tenant;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
        $this->doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($this->doctor, ['*']);
    }

    public function test_odontogram_returns_derived_teeth_and_empty_legacy_records(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();
        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$treatmentId}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'in_progress']],
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonPath('data.records', [])
            ->assertJsonPath('data.teeth.16.state', 'in_progress')
            ->assertJsonPath('data.treatment_status_by_tooth.16', 'in_progress')
            ->assertJsonCount(1, 'data.teeth.16.treatments')
            ->assertJsonPath('data.teeth.16.treatments.0.id', $treatmentId);
    }

    public function test_odontogram_done_extraction_shows_done_state(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $extraction = TreatmentTemplate::query()->create([
            'name_ar' => 'خلع',
            'name_en' => 'Extraction Test',
            'category' => 'surgical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 0,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $extraction->id,
            'tooth_numbers' => ['26'],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$treatmentId}/teeth", [
            'teeth' => [['tooth_number' => '26', 'status' => 'done']],
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonPath('data.teeth.26.state', 'done')
            ->assertJsonPath('data.treatment_status_by_tooth.26', 'completed')
            ->assertJsonStructure([
                'data' => [
                    'teeth' => [
                        '26' => [
                            'treatments' => [
                                ['id', 'tooth_row_id', 'service_name_ar', 'service_name_en', 'status', 'price'],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_odontogram_query_count_does_not_scale_with_tooth_rows(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $countQueries = function (int $treatmentCount) use ($patient, $template): int {
            PatientTreatment::query()->delete();

            $validTeeth = ['16', '17', '18', '21', '22', '23', '24', '25', '26', '27'];
            for ($i = 0; $i < $treatmentCount; $i++) {
                $this->postJson('/api/v1/patient-treatments', [
                    'patient_id' => $patient->id,
                    'treatment_template_id' => $template->id,
                    'tooth_numbers' => [$validTeeth[$i]],
                ])->assertCreated();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson("/api/v1/patients/{$patient->id}/odontogram")->assertOk();

            return count(DB::getQueryLog());
        };

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")->assertOk();

        $this->assertSame($countQueries(2), $countQueries(10));
    }

    public function test_odontogram_does_not_include_other_tenant_treatments(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);

        $foreignTemplate = TreatmentTemplate::query()->create([
            'name_ar' => 'x',
            'name_en' => 'Foreign Service',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $foreignTreatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $foreignTemplate->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => 100,
            'clinical_status' => 'planned',
            'financial_status' => 'unpaid',
            'consent_status' => 'not_required',
            'created_by' => User::factory()->create(['tenant_id' => $otherTenant->id])->id,
        ]);

        DB::table('patient_treatment_teeth')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'patient_treatment_id' => $foreignTreatment->id,
            'tooth_number' => '26',
            'status' => TreatmentToothStatus::PLANNED->value,
            'price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CurrentTenant::class)->set($this->tenant->id);

        $payload = $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->json('data');

        $this->assertSame('planned', $payload['teeth']['16']['state'] ?? null);
        $this->assertArrayNotHasKey('26', $payload['teeth'] ?? []);
    }

    public function test_skipped_tooth_rows_are_ignored_in_odontogram(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/patient-treatments/{$treatmentId}/teeth", [
            'teeth' => [['tooth_number' => '16', 'status' => 'skipped']],
        ])->assertOk();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonMissingPath('data.teeth.16');
    }

    public function test_odontogram_reports_fully_refunded_invoice_status(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();
        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])->assertCreated()->json('data.id');

        $invoiceId = InvoiceItem::query()->where('patient_treatment_id', $treatmentId)->value('invoice_id');
        $this->assertNotNull($invoiceId);
        Invoice::query()->whereKey($invoiceId)->update(['status' => InvoiceStatus::REFUNDED->value]);

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonPath('data.teeth.16.treatments.0.invoice_id', $invoiceId)
            ->assertJsonPath('data.teeth.16.treatments.0.invoice_status', 'refunded');
    }

    private function clinicalTemplate(): TreatmentTemplate
    {
        return TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Clinical '.uniqid(),
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
    }
}

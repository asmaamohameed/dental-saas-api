<?php

namespace Tests\Feature\Treatments;

use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillPatientTreatmentUnitAgreedPriceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SIMPLIFY_MIGRATION_PATH = 'database/migrations/2026_10_12_120000_simplify_treatment_module.php';

    public function test_backfill_migration_converts_total_agreed_price_to_unit_and_sets_tooth_rows(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $templateId = (string) str()->uuid();
        DB::table('treatment_templates')->insert([
            'id' => $templateId,
            'tenant_id' => $tenant->id,
            'name_ar' => 'علاج',
            'name_en' => 'Legacy Treatment',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => '250.00',
            'estimated_duration_minutes' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $completedAt = now()->subDay();
        $treatmentId = (string) str()->uuid();

        DB::table('patient_treatments')->insert([
            'id' => $treatmentId,
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'treatment_template_id' => $templateId,
            'scope' => 'tooth',
            'agreed_price' => '400.00',
            'clinical_status' => 'completed',
            'financial_status' => 'unpaid',
            'consent_status' => 'not_required',
            'treatment_type' => 'original',
            'priority' => 'routine',
            'completed_at' => $completedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['16', '26'] as $number) {
            DB::table('patient_treatment_teeth')->insert([
                'id' => (string) str()->uuid(),
                'tenant_id' => $tenant->id,
                'patient_treatment_id' => $treatmentId,
                'tooth_number' => $number,
                'status' => 'planned',
                'price' => '400.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('migrate:rollback', ['--path' => self::SIMPLIFY_MIGRATION_PATH]);
        $this->artisan('migrate', ['--path' => self::SIMPLIFY_MIGRATION_PATH]);

        $treatment = PatientTreatment::query()->with('teeth')->findOrFail($treatmentId);

        $this->assertSame('200.00', (string) $treatment->agreed_price);
        $this->assertCount(2, $treatment->teeth);

        foreach ($treatment->teeth as $tooth) {
            $this->assertSame('200.00', (string) $tooth->price);
            $this->assertSame('done', $tooth->status->value);
            $this->assertNotNull($tooth->completed_at);
        }
    }
}

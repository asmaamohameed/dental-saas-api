<?php

namespace Tests\Feature\Services;

use App\Enums\AuditAction;
use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreatmentTemplateCatalogAndAppointmentsTest extends TestCase
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

    public function test_visit_service_cannot_create_patient_treatment(): void
    {
        $visit = $this->createVisitService();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $visit->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['treatment_template_id']);
    }

    public function test_template_names_are_unique_per_tenant_after_normalization(): void
    {
        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'حشوة',
            'name_en' => 'Filling',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'x',
            'name_en' => '  filling  ',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name_en']);
    }

    public function test_appointment_fee_snapshots_template_default_price(): void
    {
        $service = $this->createClinicalService(['default_price' => 250]);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'service_id' => $service->id,
        ])->assertCreated()
            ->assertJsonPath('data.fee', '250.00');

        $service->update(['default_price' => 999]);

        $appointmentId = Appointment::query()->value('id');
        $this->getJson("/api/v1/appointments/{$appointmentId}")
            ->assertOk()
            ->assertJsonPath('data.fee', '250.00');
    }

    public function test_template_delete_blocked_when_used_by_appointment(): void
    {
        $service = $this->createClinicalService();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'duration_minutes' => 30,
        ]);

        $this->deleteJson("/api/v1/treatment-templates/{$service->id}")
            ->assertUnprocessable();
    }

    public function test_inactive_or_cross_tenant_service_rejected_for_appointment(): void
    {
        $inactive = $this->createClinicalService(['is_active' => false]);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
            'service_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreign = $this->createClinicalService();
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
            'service_id' => $foreign->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_template_list_filters_by_kind(): void
    {
        $this->createVisitService(['name_en' => 'Visit Service '.uniqid()]);
        $this->createClinicalService(['name_en' => 'Clinical Service '.uniqid()]);

        $visitResponse = $this->getJson('/api/v1/treatment-templates?kind=visit&paginate=false')->assertOk();
        $clinicalResponse = $this->getJson('/api/v1/treatment-templates?kind=clinical&paginate=false')->assertOk();

        foreach ($visitResponse->json('data') as $item) {
            $this->assertSame('visit', $item['category']);
        }

        foreach ($clinicalResponse->json('data') as $item) {
            $this->assertNotSame('visit', $item['category']);
        }
    }

    public function test_template_default_price_change_writes_audit_log(): void
    {
        $template = $this->createClinicalService(['default_price' => 100]);

        $this->putJson("/api/v1/treatment-templates/{$template->id}", [
            'name_ar' => $template->name_ar,
            'name_en' => $template->name_en,
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 150,
            'estimated_duration_minutes' => 30,
        ])->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::TREATMENT_TEMPLATE_DEFAULT_PRICE_CHANGED)
            ->where('auditable_id', $template->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('100.00', $log->old_values['default_price'] ?? null);
        $this->assertSame('150.00', $log->new_values['default_price'] ?? null);
    }

    public function test_appointment_index_query_count_does_not_scale_with_appointment_count(): void
    {
        $service = $this->createClinicalService();
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);

        $seedAppointments = function (int $appointmentCount) use ($patient, $doctor, $service): void {
            Appointment::query()->delete();

            for ($i = 0; $i < $appointmentCount; $i++) {
                Appointment::factory()->create([
                    'tenant_id' => $this->tenant->id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'service_id' => $service->id,
                    'duration_minutes' => 30,
                    'scheduled_at' => now()->addDays($i + 1),
                ]);
            }
        };

        $countIndexQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/v1/appointments?per_page=100')->assertOk();

            return count(DB::getQueryLog());
        };

        $seedAppointments(15);
        $this->getJson('/api/v1/appointments?per_page=100')->assertOk();

        $seedAppointments(3);
        $queriesForThree = $countIndexQueries();

        $seedAppointments(15);
        $queriesForFifteen = $countIndexQueries();

        $this->assertSame($queriesForThree, $queriesForFifteen);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVisitService(array $overrides = []): TreatmentTemplate
    {
        return TreatmentTemplate::query()->create(array_merge([
            'name_ar' => 'زيارة',
            'name_en' => 'Visit '.uniqid(),
            'category' => TreatmentCategory::VISIT,
            'scope' => TreatmentScope::FULL_MOUTH,
            'default_price' => 0,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createClinicalService(array $overrides = []): TreatmentTemplate
    {
        return TreatmentTemplate::query()->create(array_merge([
            'name_ar' => 'علاج',
            'name_en' => 'Clinical '.uniqid(),
            'category' => TreatmentCategory::MEDICAL,
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 200,
            'estimated_duration_minutes' => 45,
            'is_active' => true,
        ], $overrides));
    }
}

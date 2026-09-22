<?php

namespace Tests\Feature\Treatments;

use App\Enums\PatientTreatmentVisitStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentComponent;
use App\Models\PatientTreatmentVisit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitComponentStockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_completing_visit_deducts_component_stock(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($user, ['*']);

        $component = Component::query()->create([
            'name_ar' => 'قفاز',
            'name_en' => 'Gloves',
            'default_price' => 10,
            'unit' => 'box',
            'current_quantity' => 20,
            'minimum_threshold' => 5,
            'is_active' => true,
        ]);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'status' => 'planned',
            'priority' => 'routine',
            'total_visits' => 1,
            'actual_price' => 100,
            'created_by' => $user->id,
        ]);

        $visit = PatientTreatmentVisit::query()->create([
            'patient_treatment_id' => $treatment->id,
            'visit_order' => 1,
            'name' => 'Visit 1',
            'status' => PatientTreatmentVisitStatus::PLANNED,
        ]);

        PatientTreatmentComponent::query()->create([
            'patient_treatment_visit_id' => $visit->id,
            'component_id' => $component->id,
            'name' => 'Gloves',
            'unit_price' => 10,
            'quantity' => 3,
            'free_quantity' => 0,
            'unit' => 'box',
        ]);

        $this->patchJson("/api/v1/patient-treatment-visits/{$visit->id}", [
            'status' => 'completed',
        ])->assertOk();

        $component->refresh();

        $this->assertSame('17.00', (string) $component->current_quantity);
    }
}

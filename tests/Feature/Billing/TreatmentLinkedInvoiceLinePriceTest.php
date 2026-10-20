<?php

namespace Tests\Feature\Billing;

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
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreatmentLinkedInvoiceLinePriceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_receptionist_post_with_client_price_stores_treatment_price(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        [$invoice, $treatment] = $this->unpaidInvoiceWithTreatment(80);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'patient_treatment_id' => $treatment->id,
            'price' => 9999,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertSame('80.00', (string) $invoice->items()->latest('created_at')->first()->price);
    }

    public function test_receptionist_patch_with_client_price_keeps_treatment_price(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        [$invoice, $treatment, $item] = $this->unpaidInvoiceWithTreatmentLine(80);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'patient_treatment_id' => $treatment->id,
            'price' => 9999,
            'quantity' => 1,
        ])->assertOk();

        $this->assertSame('80.00', (string) $item->fresh()->price);
    }

    public function test_receptionist_patch_existing_treatment_line_ignores_price_without_resending_treatment_id(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        [$invoice, , $item] = $this->unpaidInvoiceWithTreatmentLine(80);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 12.5,
            'quantity' => 1,
        ])->assertOk();

        $this->assertSame('80.00', (string) $item->fresh()->price);
        $this->assertNotNull($item->fresh()->patient_treatment_id);
    }

    public function test_receptionist_put_with_client_price_stores_treatment_price(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        [$invoice, $treatment, $item] = $this->unpaidInvoiceWithTreatmentLine(80);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [[
                'id' => $item->id,
                'patient_treatment_id' => $treatment->id,
                'price' => 9999,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $this->assertSame('80.00', (string) $item->fresh()->price);
    }

    public function test_receptionist_put_attaching_treatment_to_other_line_uses_treatment_price(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::UNPAID,
            'total_amount' => 100,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Manual billing line item',
            'price' => 100,
            'quantity' => 1,
        ]);
        $treatment = $this->makeToothTreatment($patient, 80);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [[
                'id' => $item->id,
                'patient_treatment_id' => $treatment->id,
                'price' => 9999,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $this->assertSame('80.00', (string) $item->fresh()->price);
        $this->assertSame($treatment->id, $item->fresh()->patient_treatment_id);
    }

    public function test_owner_and_doctor_also_store_treatment_price_not_client_price(): void
    {
        foreach ([UserRole::OWNER, UserRole::DOCTOR] as $role) {
            $this->actingAsRole($role);
            [$invoice, $treatment] = $this->unpaidInvoiceWithTreatment(80);

            $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
                'patient_treatment_id' => $treatment->id,
                'price' => 555,
                'quantity' => 1,
            ])->assertCreated();

            $this->assertSame('80.00', (string) $invoice->items()->latest('created_at')->first()->price);
        }
    }

    public function test_receptionist_manual_line_price_rules_are_unchanged(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Manual billing line item',
            'price' => 100,
            'quantity' => 1,
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'price' => 50,
            'description' => 'Another manual billing line',
            'quantity' => 1,
        ])->assertForbidden();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 150,
        ])->assertForbidden();
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * @return array{0: Invoice, 1: PatientTreatment}
     */
    private function unpaidInvoiceWithTreatment(float $treatmentPrice): array
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::UNPAID,
            'total_amount' => 0,
        ]);
        $treatment = $this->makeToothTreatment($patient, $treatmentPrice);

        return [$invoice, $treatment];
    }

    /**
     * @return array{0: Invoice, 1: PatientTreatment, 2: InvoiceItem}
     */
    private function unpaidInvoiceWithTreatmentLine(float $treatmentPrice): array
    {
        [$invoice, $treatment] = $this->unpaidInvoiceWithTreatment($treatmentPrice);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'description' => 'Treatment line',
            'price' => $treatmentPrice,
            'quantity' => 1,
        ]);

        return [$invoice, $treatment, $item];
    }

    private function makeToothTreatment(Patient $patient, float $toothPrice): PatientTreatment
    {
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => $toothPrice,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => $toothPrice,
            'clinical_status' => PatientTreatmentStatus::PLANNED,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);
        PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '16',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => $toothPrice,
        ]);

        return $treatment;
    }
}

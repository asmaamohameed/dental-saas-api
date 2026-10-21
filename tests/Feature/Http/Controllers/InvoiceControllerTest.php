<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // ---------- index ----------

    public function test_index_returns_invoices_for_current_tenant(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        Invoice::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/invoices')->assertOk();

        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_index_orders_invoices_newest_first(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $older = Invoice::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = Invoice::factory()->create(['created_at' => now()->subDay()]);

        $items = $this->getJson('/api/v1/invoices')->assertOk()->json('data.items');

        $this->assertSame($newer->id, $items[0]['id']);
        $this->assertSame($older->id, $items[1]['id']);
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        Invoice::factory()->create(['status' => InvoiceStatus::PAID]);
        Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $response = $this->getJson('/api/v1/invoices?status=unpaid')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_index_filters_by_patient(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        Invoice::factory()->create(['patient_id' => $patient->id]);
        Invoice::factory()->create();

        $response = $this->getJson("/api/v1/invoices?patient_id={$patient->id}")->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_index_filters_by_search_case_insensitively(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create(['full_name' => 'Unique Patient Alpha']);
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        Invoice::factory()->create();

        $byPatient = $this->getJson('/api/v1/invoices?search=unique+patient')->assertOk();
        $this->assertCount(1, $byPatient->json('data.items'));
        $this->assertSame($invoice->id, $byPatient->json('data.items.0.id'));

        $byId = $this->getJson('/api/v1/invoices?search='.strtoupper($invoice->id))->assertOk();
        $this->assertCount(1, $byId->json('data.items'));
        $this->assertSame($invoice->id, $byId->json('data.items.0.id'));
    }

    public function test_index_does_not_return_another_tenants_invoices(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $otherTenant = Tenant::factory()->create();
        $myTenantId = app(CurrentTenant::class)->id();
        app(CurrentTenant::class)->set($otherTenant->id);
        Invoice::factory()->count(2)->create();
        app(CurrentTenant::class)->set($myTenantId);

        Invoice::factory()->create();

        $response = $this->getJson('/api/v1/invoices')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_post_invoices_returns_not_found(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['price' => 100, 'description' => 'Manual charge line', 'quantity' => 1]],
        ])->assertMethodNotAllowed();
    }

    // ---------- show ----------

    public function test_show_returns_the_invoice_with_relations(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create();

        $this->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id);
    }

    public function test_show_returns_404_for_an_invoice_in_another_tenant(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $otherTenant = Tenant::factory()->create();
        $myTenantId = app(CurrentTenant::class)->id();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignInvoice = Invoice::factory()->create();
        app(CurrentTenant::class)->set($myTenantId);

        $this->getJson("/api/v1/invoices/{$foreignInvoice->id}")->assertStatus(404);
    }

    public function test_put_invoices_returns_not_found(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $invoice->patient_id,
        ])->assertMethodNotAllowed();
    }

    // ---------- destroy ----------

    public function test_destroy_soft_deletes_an_invoice_without_payments(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    public function test_destroy_returns_a_clear_error_when_invoice_has_payments(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot cancel an invoice that has recorded payments.');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);
    }

    public function test_receptionist_cannot_delete_an_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertStatus(403);
    }

    // ---------- patientSummary ----------

    public function test_patient_summary_aggregates_totals_correctly(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id, 'total_amount' => 200]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 150]);

        $response = $this->getJson("/api/v1/patients/{$patient->id}/invoices-summary")->assertOk();

        $this->assertEquals(200, $response->json('data.total_billed'));
        $this->assertEquals(150, $response->json('data.total_paid'));
        $this->assertEquals(50, $response->json('data.total_remaining'));
    }
}

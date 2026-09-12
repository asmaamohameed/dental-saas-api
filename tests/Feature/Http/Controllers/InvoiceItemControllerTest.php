<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceItemControllerTest extends TestCase
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

    public function test_index_returns_items_for_own_tenant_invoice(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->count(2)->create(['invoice_id' => $invoice->id]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_returns_404_for_invoice_in_another_tenant(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $otherTenant = Tenant::factory()->create();
        $myTenantId = app(CurrentTenant::class)->id();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignInvoice = Invoice::factory()->create();
        app(CurrentTenant::class)->set($myTenantId);

        $this->getJson("/api/v1/invoices/{$foreignInvoice->id}/items")->assertStatus(404);
    }

    public function test_doctor_is_forbidden_from_adding_items(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $service = Service::factory()->create(['is_other' => false]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $service->id,
            'quantity' => 1,
        ])->assertStatus(403);
    }

    public function test_receptionist_can_add_an_item_and_total_is_recalculated(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 0]);
        $service = Service::factory()->create(['default_price' => 80, 'is_other' => false]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $service->id,
            'price' => 999,
            'quantity' => 2,
        ])->assertCreated();

        $this->assertEquals(160, $invoice->fresh()->total_amount);
    }

    public function test_price_is_forced_from_service_default_price_ignoring_submitted_value(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 0]);
        $service = Service::factory()->create(['default_price' => 50, 'is_other' => false]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $service->id,
            'price' => 5000,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertEquals(50, $invoice->fresh()->total_amount);
    }

    public function test_submitted_price_is_respected_for_the_other_service(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 0]);
        $otherService = Service::factory()->create(['is_other' => true]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $otherService->id,
            'description' => 'Custom procedure',
            'price' => 350,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertEquals(350, $invoice->fresh()->total_amount);
    }

    public function test_cannot_add_an_item_to_a_fully_paid_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PAID]);
        $service = Service::factory()->create(['is_other' => false]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'service_id' => $service->id,
            'price' => 10,
            'quantity' => 1,
        ])->assertStatus(403);
    }

    public function test_update_rejects_a_new_total_lower_than_payments_already_received(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 1000]);
        $service = Service::factory()->create(['default_price' => 100, 'is_other' => false]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id, 'service_id' => $service->id, 'price' => 100, 'quantity' => 10,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
    }

    public function test_update_returns_404_when_item_does_not_belong_to_the_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoiceA = Invoice::factory()->create();
        $invoiceB = Invoice::factory()->create();
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoiceB->id]);

        $this->putJson("/api/v1/invoices/{$invoiceA->id}/items/{$item->id}", [
            'quantity' => 3,
        ])->assertStatus(404);
    }

    public function test_destroy_rejects_deleting_the_last_remaining_item(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['item']);
    }

    public function test_destroy_removes_an_item_and_recalculates_total_when_another_item_remains(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $serviceA = Service::factory()->create(['default_price' => 100, 'is_other' => false]);
        $serviceB = Service::factory()->create(['default_price' => 50, 'is_other' => false]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $serviceA->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        $itemToDelete = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $serviceB->id,
            'price' => 50,
            'quantity' => 1,
        ]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$itemToDelete->id}")->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $itemToDelete->id]);
        $this->assertEquals(100, $invoice->fresh()->total_amount);
    }

    public function test_destroy_returns_404_when_item_does_not_belong_to_the_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoiceA = Invoice::factory()->create();
        $invoiceB = Invoice::factory()->create();
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoiceB->id]);

        $this->deleteJson("/api/v1/invoices/{$invoiceA->id}/items/{$item->id}")->assertStatus(404);
    }
}

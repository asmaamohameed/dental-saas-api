<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
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

    private function manualItemPayload(array $overrides = []): array
    {
        return array_merge([
            'price' => 80,
            'description' => 'Manual billing line item',
            'quantity' => 1,
        ], $overrides);
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

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())
            ->assertCreated();
    }

    public function test_receptionist_can_add_an_item_and_total_is_recalculated(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 0]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload([
            'price' => 80,
            'quantity' => 2,
        ]))->assertCreated();

        $this->assertEquals(160, $invoice->fresh()->total_amount);
    }

    public function test_can_add_an_item_to_a_paid_zero_fee_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PAID, 'total_amount' => 0]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())
            ->assertCreated();

        $this->assertEquals(80, $invoice->fresh()->total_amount);
    }

    public function test_update_below_payments_sets_refund_due_and_does_not_create_a_refund(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 1000]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Existing line',
            'price' => 100,
            'quantity' => 10,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 1,
        ])->assertOk();

        $fresh = $invoice->fresh();
        $this->assertEquals(100, $fresh->total_amount);
        $this->assertSame('200.00', $fresh->balance()['refund_due']);
        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund->value)->count());
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

    public function test_destroy_can_remove_the_only_planned_line(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Only line',
            'price' => 40,
            'quantity' => 1,
        ]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $item->id]);
        $this->assertEquals(0, $invoice->fresh()->total_amount);
    }

    public function test_destroy_removes_an_item_and_recalculates_total_when_another_item_remains(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'First line',
            'price' => 100,
            'quantity' => 1,
        ]);
        $itemToDelete = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Second line',
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

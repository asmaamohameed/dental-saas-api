<?php

namespace Tests\Feature\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceItemPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
    }

    private function invoiceWithStatus(InvoiceStatus $status): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => $status,
        ]);
    }

    private function manualItemPayload(): array
    {
        return [
            'price' => 100,
            'description' => 'Additional charge line item',
            'quantity' => 1,
        ];
    }

    public function test_receptionist_can_view_invoice_items(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertOk();
    }

    public function test_user_from_another_tenant_cannot_view_invoice_items(): void
    {
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($outsider, ['*']);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertNotFound();
    }

    public function test_receptionist_can_add_item_to_unpaid_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertCreated();
    }

    public function test_receptionist_cannot_add_item_to_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertForbidden();
    }

    public function test_owner_can_add_item_to_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertCreated();
    }

    public function test_nobody_can_add_item_to_a_paid_invoice_not_even_the_owner(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PAID);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertForbidden();
    }

    public function test_doctor_cannot_add_invoice_items(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertForbidden();
    }

    public function test_owner_can_delete_an_item_from_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();
    }

    public function test_receptionist_cannot_delete_an_item_from_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertForbidden();
    }

    public function test_doctor_cannot_delete_an_invoice_item(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertForbidden();
    }
}

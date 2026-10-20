<?php

namespace Tests\Feature\Authorization;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualInvoiceLinePriceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function receptionist(): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function unpaidInvoiceWithManualLine(): array
    {
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

        return [$invoice, $item];
    }

    public function test_receptionist_can_put_existing_manual_line_when_price_unchanged(): void
    {
        $this->receptionist();
        [$invoice, $item] = $this->unpaidInvoiceWithManualLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $item->id,
                    'price' => 100,
                    'description' => 'Updated manual billing note',
                    'quantity' => 2,
                ],
            ],
        ])->assertOk();

        $item->refresh();
        $this->assertSame('Updated manual billing note', $item->description);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('100.00', (string) $item->price);
    }

    public function test_receptionist_can_patch_manual_line_when_price_key_unchanged(): void
    {
        $this->receptionist();
        [$invoice, $item] = $this->unpaidInvoiceWithManualLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 100,
            'description' => 'Patched manual billing note',
            'quantity' => 1,
        ])->assertOk();

        $this->assertSame('Patched manual billing note', $item->fresh()->description);
    }

    public function test_receptionist_gets_forbidden_when_changing_manual_line_price(): void
    {
        $this->receptionist();
        [$invoice, $item] = $this->unpaidInvoiceWithManualLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 150,
        ])->assertForbidden();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $item->id,
                    'price' => 150,
                    'description' => 'Manual billing line item',
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_receptionist_gets_forbidden_when_adding_new_manual_line_via_put(): void
    {
        $this->receptionist();
        [$invoice] = $this->unpaidInvoiceWithManualLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'price' => 50,
                    'description' => 'Another manual billing line',
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_receptionist_gets_forbidden_when_item_id_belongs_to_another_invoice(): void
    {
        $this->receptionist();
        [$invoiceA] = $this->unpaidInvoiceWithManualLine();
        [$invoiceB, $itemOnB] = $this->unpaidInvoiceWithManualLine();

        $this->putJson("/api/v1/invoices/{$invoiceA->id}", [
            'items' => [
                [
                    'id' => $itemOnB->id,
                    'price' => 200,
                    'description' => 'Cross invoice attempt line',
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();

        $this->assertSame($invoiceB->id, $itemOnB->fresh()->invoice_id);
    }

    public function test_receptionist_can_change_price_after_owner_grants_permission(): void
    {
        $this->receptionist();
        [$invoice, $item] = $this->unpaidInvoiceWithManualLine();

        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['treatment:change_price'] = true;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        $this->receptionist();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 125,
        ])->assertOk();

        $this->assertSame('125.00', (string) $item->fresh()->price);
    }
}

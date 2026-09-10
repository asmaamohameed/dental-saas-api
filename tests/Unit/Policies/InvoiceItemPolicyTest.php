<?php

namespace Tests\Unit\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Policies\InvoiceItemPolicy;
use Tests\TestCase;

class InvoiceItemPolicyTest extends TestCase
{
    private InvoiceItemPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new InvoiceItemPolicy;
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a'): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);
    }

    private function invoice(string $tenantId = 'tenant-a', InvoiceStatus $status = InvoiceStatus::UNPAID): Invoice
    {
        $invoice = new Invoice;
        $invoice->tenant_id = $tenantId;
        $invoice->status = $status;

        return $invoice;
    }

    private function invoiceItem(Invoice $invoice): InvoiceItem
    {
        $item = new InvoiceItem;
        $item->setRelation('invoice', $invoice);

        return $item;
    }

    public function test_before_defers_create_update_delete_even_for_owner(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $this->assertNull($this->policy->before($owner, 'create'));
        $this->assertNull($this->policy->before($owner, 'update'));
        $this->assertNull($this->policy->before($owner, 'delete'));
    }

    public function test_before_grants_owner_other_abilities(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'viewAny'));
    }

    public function test_view_any_denies_cross_tenant_access(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->viewAny($doctor, $this->invoice('tenant-a')));
        $this->assertFalse($this->policy->viewAny($doctor, $this->invoice('tenant-b')));
    }

    public function test_create_is_denied_when_invoice_is_paid_even_for_owner(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $this->assertFalse($this->policy->create($owner, $this->invoice('tenant-a', InvoiceStatus::PAID)));
    }

    public function test_create_allows_owner_when_not_paid(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $this->assertTrue($this->policy->create($owner, $this->invoice('tenant-a', InvoiceStatus::UNPAID)));
    }

    public function test_create_allows_receptionist_on_unpaid_invoice_same_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertTrue($this->policy->create($receptionist, $this->invoice('tenant-a', InvoiceStatus::UNPAID)));
    }

    public function test_create_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertFalse($this->policy->create($receptionist, $this->invoice('tenant-a', InvoiceStatus::PARTIAL)));
    }

    public function test_create_denies_receptionist_cross_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertFalse($this->policy->create($receptionist, $this->invoice('tenant-b', InvoiceStatus::UNPAID)));
    }

    public function test_create_denies_doctor(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertFalse($this->policy->create($doctor, $this->invoice('tenant-a', InvoiceStatus::UNPAID)));
    }

    public function test_update_is_denied_when_invoice_is_paid_even_for_owner(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $item = $this->invoiceItem($this->invoice('tenant-a', InvoiceStatus::PAID));

        $this->assertFalse($this->policy->update($owner, $item));
    }

    public function test_update_allows_receptionist_on_unpaid_invoice_same_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');
        $item = $this->invoiceItem($this->invoice('tenant-a', InvoiceStatus::UNPAID));

        $this->assertTrue($this->policy->update($receptionist, $item));
    }

    public function test_update_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');
        $item = $this->invoiceItem($this->invoice('tenant-a', InvoiceStatus::PARTIAL));

        $this->assertFalse($this->policy->update($receptionist, $item));
    }

    public function test_delete_is_denied_when_invoice_is_paid_even_for_owner(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $item = $this->invoiceItem($this->invoice('tenant-a', InvoiceStatus::PAID));

        $this->assertFalse($this->policy->delete($owner, $item));
    }

    public function test_delete_denies_receptionist_cross_tenant(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');
        $item = $this->invoiceItem($this->invoice('tenant-b', InvoiceStatus::UNPAID));

        $this->assertFalse($this->policy->delete($receptionist, $item));
    }
}

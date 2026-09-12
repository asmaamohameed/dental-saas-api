<?php

namespace Tests\Unit\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\InvoicePolicy;
use Tests\TestCase;

class InvoicePolicyTest extends TestCase
{
    private InvoicePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new InvoicePolicy;
    }

    private function user(UserRole $role): User
    {
        return User::factory()->make(['role' => $role]);
    }

    private function invoice(InvoiceStatus $status): Invoice
    {
        return new Invoice(['status' => $status]);
    }

    public function test_before_grants_owner_every_ability(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'updateItems'));
    }

    public function test_update_items_denies_doctor(): void
    {
        $doctor = $this->user(UserRole::DOCTOR);

        $this->assertFalse($this->policy->updateItems($doctor, $this->invoice(InvoiceStatus::UNPAID)));
    }

    public function test_update_items_allows_receptionist_on_unpaid_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST);

        $this->assertTrue($this->policy->updateItems($receptionist, $this->invoice(InvoiceStatus::UNPAID)));
    }

    public function test_update_items_denies_receptionist_on_partial_invoice(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST);

        $this->assertFalse($this->policy->updateItems($receptionist, $this->invoice(InvoiceStatus::PARTIAL)));
    }

    public function test_update_items_allows_owner_on_partial_invoice_via_direct_call(): void
    {
        $owner = $this->user(UserRole::OWNER);

        $this->assertTrue($this->policy->updateItems($owner, $this->invoice(InvoiceStatus::PARTIAL)));
    }
}

<?php

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    public function test_guest_cannot_load_overview(): void
    {
        $this->getJson('/api/v1/dashboard/overview')->assertUnauthorized();
    }

    public function test_owner_receives_overview_payload(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        Patient::factory()->create();

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $response->assertJsonPath('status', 'success');
        $this->assertIsArray($response->json('data.today_appointments'));
        $this->assertSame(1, $response->json('data.total_patients'));
        $this->assertIsArray($response->json('data.invoices'));
        $this->assertArrayHasKey('total_invoiced_all_time', $response->json('data'));
        $this->assertArrayHasKey('outstanding_balance_all_time', $response->json('data'));
        $this->assertIsArray($response->json('data.low_stock_items'));
        $this->assertIsArray($response->json('data.expiry_alert_items'));
    }

    public function test_doctor_without_financials_does_not_receive_invoice_data(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $this->assertSame([], $response->json('data.invoices'));
        $this->assertNull($response->json('data.total_invoiced_all_time'));
        $this->assertNull($response->json('data.outstanding_balance_all_time'));
        $this->assertSame([], $response->json('data.low_stock_items'));
        $this->assertSame([], $response->json('data.expiry_alert_items'));
    }

    public function test_overview_does_not_count_patients_from_another_tenant(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        Patient::factory()->create();

        $foreign = Tenant::factory()->create();
        app(CurrentTenant::class)->set($foreign->id);
        Patient::factory()->create();

        app(CurrentTenant::class)->set($owner->tenant_id);
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/dashboard/overview')->assertOk();

        $this->assertSame(1, $response->json('data.total_patients'));
    }
}

<?php

namespace Tests\Feature\Http\Controllers\Requests;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoicePriceValidationTest extends TestCase
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

    public function test_store_does_not_require_price_for_a_regular_service(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $service = Service::factory()->create(['default_price' => 120, 'is_other' => false]);

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['service_id' => $service->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total_amount', 120);
    }

    public function test_store_requires_price_for_the_other_service(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $otherService = Service::factory()->create(['is_other' => true]);

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['service_id' => $otherService->id, 'description' => 'Custom', 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);
    }

    public function test_store_accepts_price_for_the_other_service_when_provided(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $otherService = Service::factory()->create(['is_other' => true]);

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [
                [
                    'service_id' => $otherService->id,
                    'description' => 'Custom work',
                    'price' => 300,
                    'quantity' => 1,
                ],
            ],
        ])->assertCreated()->assertJsonPath('data.total_amount', 300);
    }

    public function test_update_does_not_require_price_for_a_regular_service(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $service = Service::factory()->create(['default_price' => 90, 'is_other' => false]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [['service_id' => $service->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.total_amount', 90);
    }

    public function test_update_requires_price_for_the_other_service(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $otherService = Service::factory()->create(['is_other' => true]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [['service_id' => $otherService->id, 'description' => 'Custom', 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.price']);
    }
}

<?php

namespace Tests\Feature\Http\Controllers\Requests;

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

    public function test_update_accepts_manual_line_item(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [['price' => 90, 'description' => 'Manual billing line', 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.total_amount', 90);
    }

    public function test_store_requires_description_when_no_treatment_is_selected(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['price' => 50, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.description']);
    }

    public function test_store_rejects_short_note_when_no_treatment_is_selected(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['price' => 50, 'description' => 'abc', 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.description']);
    }

    public function test_store_accepts_amount_only_line_with_description(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'items' => [['price' => 50, 'description' => 'Consultation fee', 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total_amount', 50);
    }
}

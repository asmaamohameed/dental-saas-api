<?php

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Tests\TestCase;

class FinancialAnalyticsTest extends TestCase
{
    public function test_owner_can_fetch_financial_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->owner()->create(['tenant_id' => $tenant->id]);
        $this->actingAsTenantUser($tenant, UserRole::OWNER);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);

        // Create invoices
        Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'total_amount' => 500.00,
            'status' => 'unpaid',
            'created_by' => $owner->id,
            'due_date' => Carbon::now()->subDays(5)->toDateString(),
        ]);

        // Create expense
        Expense::create([
            'tenant_id' => $tenant->id,
            'category' => 'electricity',
            'title' => 'Power Bill',
            'amount' => 120.00,
            'expense_date' => Carbon::now()->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->getJson('/api/v1/dashboard/financial-analytics');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'current_month',
                    'period' => ['from', 'to'],
                    'kpis' => [
                        'total_invoiced_mtd',
                        'total_collected_mtd',
                        'total_expenses_mtd',
                        'net_profit_mtd',
                        'collection_rate_mtd',
                        'outstanding_balance_all_time',
                        'overdue_amount_all_time',
                    ],
                    'invoices_summary_mtd',
                    'expenses_breakdown_mtd',
                    'charts' => ['categories', 'series'],
                ],
            ]);
    }

    public function test_non_owner_cannot_access_financial_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $this->actingAsTenantUser($tenant, UserRole::DOCTOR);

        $response = $this->getJson('/api/v1/dashboard/financial-analytics');
        $response->assertForbidden();
    }

    public function test_owner_can_manage_expenses(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->owner()->create(['tenant_id' => $tenant->id]);
        $this->actingAsTenantUser($tenant, UserRole::OWNER);

        // Store
        $storeRes = $this->postJson('/api/v1/expenses', [
            'category' => 'internet',
            'title' => 'Office Internet',
            'amount' => 80.00,
            'expense_date' => Carbon::now()->toDateString(),
            'notes' => 'High speed fiber',
        ]);

        $storeRes->assertCreated()
            ->assertJsonPath('data.category', 'internet')
            ->assertJsonPath('data.title', 'Office Internet');

        $expenseId = $storeRes->json('data.id');

        // Index
        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Delete
        $this->deleteJson("/api/v1/expenses/{$expenseId}")
            ->assertOk();

        $this->assertSoftDeleted('expenses', ['id' => $expenseId]);
    }

    public function test_owner_can_fetch_income_expense_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->owner()->create(['tenant_id' => $tenant->id]);
        $this->actingAsTenantUser($tenant, UserRole::OWNER);

        $response = $this->getJson('/api/v1/dashboard/income-expense?range=this_month');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'total_income',
                    'income_change_pct',
                    'total_expenses',
                    'expense_change_pct',
                    'series' => [
                        '*' => ['period', 'income', 'expenses'],
                    ],
                ],
            ]);
    }

    public function test_owner_can_fetch_patient_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->owner()->create(['tenant_id' => $tenant->id]);
        $this->actingAsTenantUser($tenant, UserRole::OWNER);

        $response = $this->getJson('/api/v1/dashboard/patients?range=this_month');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'scheduled',
                    'completed',
                    'no_show',
                    'cancelled',
                ],
            ]);
    }

    public function test_owner_can_fetch_cashflow_analytics(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $owner = User::factory()->owner()->create(['tenant_id' => $tenant->id]);
        $this->actingAsTenantUser($tenant, UserRole::OWNER);

        $response = $this->getJson('/api/v1/dashboard/cashflow?range=this_month');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'total',
                    'change_pct',
                    'points' => [
                        '*' => ['date', 'amount'],
                    ],
                ],
            ]);
    }
}

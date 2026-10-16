<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    public function test_component_stock_endpoint_is_removed(): void
    {
        $this->actingAsTenantUser();

        $this->getJson('/api/v1/dashboard/component-stock')->assertNotFound();
    }

    public function test_financial_analytics_expense_breakdown_excludes_components_category(): void
    {
        $user = $this->actingAsTenantUser();

        Expense::query()->create([
            'category' => ExpenseCategory::RENT,
            'title' => 'Office rent',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $response = $this->getJson('/api/v1/dashboard/financial-analytics')->assertOk();

        $breakdown = $response->json('data.expenses_breakdown_mtd');
        $this->assertIsArray($breakdown);
        $this->assertArrayNotHasKey('components', $breakdown);
        $this->assertSame(
            ['electricity', 'internet', 'rent', 'water', 'salary', 'lab', 'other'],
            array_keys($breakdown)
        );
        $this->assertSame(100.0, (float) $breakdown['rent']);
    }

    public function test_expense_breakdown_totals_sum_expense_categories_only(): void
    {
        $user = $this->actingAsTenantUser();

        $date = now()->toDateString();
        Expense::query()->create([
            'category' => ExpenseCategory::ELECTRICITY,
            'title' => 'Power',
            'amount' => 50,
            'expense_date' => $date,
            'created_by' => $user->id,
        ]);
        Expense::query()->create([
            'category' => ExpenseCategory::OTHER,
            'title' => 'Misc',
            'amount' => 25,
            'expense_date' => $date,
            'created_by' => $user->id,
        ]);

        $data = $this->getJson('/api/v1/dashboard/expense-breakdown')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('components', $data);
        $this->assertSame(75.0, (float) $data['total']);
        $this->assertSame(50.0, (float) $data['electricity']);
        $this->assertSame(25.0, (float) $data['other']);
    }

    public function test_other_dashboard_endpoints_keep_expected_top_level_keys(): void
    {
        $this->actingAsTenantUser();

        $this->getJson('/api/v1/dashboard/income-expense')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_income',
                    'income_change_pct',
                    'total_expenses',
                    'expense_change_pct',
                    'series',
                ],
            ]);

        $this->getJson('/api/v1/dashboard/patients')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'scheduled',
                    'checked_in',
                    'in_progress',
                    'completed',
                    'no_show',
                    'cancelled',
                ],
            ]);

        $this->getJson('/api/v1/dashboard/cashflow')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'change_pct',
                    'points',
                ],
            ]);

        $this->getJson('/api/v1/dashboard/invoice-status')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'paid',
                    'partial',
                    'unpaid',
                    'total',
                ],
            ]);
    }
}

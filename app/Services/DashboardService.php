<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ExpenseCategory;
use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class DashboardService
{
    private function parseDateRange(?string $range): array
    {
        $now = Carbon::now();

        switch ($range) {
            case 'last_month':
                $start = $now->copy()->subMonth()->startOfMonth();
                $end = $now->copy()->subMonth()->endOfMonth();
                $prevStart = $now->copy()->subMonths(2)->startOfMonth();
                $prevEnd = $now->copy()->subMonths(2)->endOfMonth();
                break;
            case 'last_3_months':
                $start = $now->copy()->subMonths(3)->startOfMonth();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subMonths(6)->startOfMonth();
                $prevEnd = $now->copy()->subMonths(3)->startOfMonth()->subSecond();
                break;
            case 'last_6_months':
                $start = $now->copy()->subMonths(6)->startOfMonth();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subMonths(12)->startOfMonth();
                $prevEnd = $now->copy()->subMonths(6)->startOfMonth()->subSecond();
                break;
            case 'last_12_months':
                $start = $now->copy()->subMonths(12)->startOfMonth();
                $end = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subMonths(24)->startOfMonth();
                $prevEnd = $now->copy()->subMonths(12)->startOfMonth()->subSecond();
                break;
            case 'this_month':
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $prevStart = $now->copy()->subMonth()->startOfMonth();
                $prevEnd = $now->copy()->subMonth()->endOfMonth();
                break;
        }

        return [$start, $end, $prevStart, $prevEnd];
    }

    public function getFinancialAnalytics(): array
    {
        $now = Carbon::now();
        $startMtd = $now->copy()->startOfMonth();
        $endMtd = $now->copy()->endOfMonth();

        // 1. KPIs
        $totalInvoicedMtd = (float) Invoice::whereBetween('created_at', [$startMtd, $endMtd])->sum('total_amount');
        $totalCollectedMtd = (float) Payment::whereBetween('created_at', [$startMtd, $endMtd])->sum('amount');
        $totalExpensesMtd = (float) Expense::whereBetween('expense_date', [$startMtd->format('Y-m-d'), $endMtd->format('Y-m-d')])->sum('amount');
        $netProfitMtd = $totalCollectedMtd - $totalExpensesMtd;
        $collectionRateMtd = $totalInvoicedMtd > 0 ? round(($totalCollectedMtd / $totalInvoicedMtd) * 100, 1) : 0.0;

        $totalAllInvoiced = (float) Invoice::sum('total_amount');
        $totalAllPayments = (float) Payment::sum('amount');
        $outstandingAllTime = max(0.0, $totalAllInvoiced - $totalAllPayments);

        $overdueAmount = (float) Invoice::whereIn('status', [InvoiceStatus::UNPAID->value, InvoiceStatus::PARTIAL->value])
            ->where('created_at', '<', $now->copy()->startOfDay())
            ->get()
            ->sum('remaining_amount');

        // 2. Invoices summary MTD
        $invoicesMtd = Invoice::whereBetween('created_at', [$startMtd, $endMtd])->get();
        $invoicesSummaryMtd = [
            'total_invoices' => $invoicesMtd->count(),
            'paid_invoices' => $invoicesMtd->filter(fn ($i) => ($i->status instanceof InvoiceStatus ? $i->status->value : $i->status) === InvoiceStatus::PAID->value)->count(),
            'partially_paid_invoices' => $invoicesMtd->filter(fn ($i) => ($i->status instanceof InvoiceStatus ? $i->status->value : $i->status) === InvoiceStatus::PARTIAL->value)->count(),
            'unpaid_invoices' => $invoicesMtd->filter(fn ($i) => ($i->status instanceof InvoiceStatus ? $i->status->value : $i->status) === InvoiceStatus::UNPAID->value)->count(),
        ];

        // 3. Expenses breakdown MTD
        $expensesMtd = Expense::whereBetween('expense_date', [$startMtd->format('Y-m-d'), $endMtd->format('Y-m-d')])->get();
        $getCategorySum = fn (ExpenseCategory $cat) => (float) $expensesMtd->filter(fn ($e) => ($e->category instanceof ExpenseCategory ? $e->category->value : $e->category) === $cat->value)->sum('amount');

        $expensesBreakdownMtd = [
            'electricity' => $getCategorySum(ExpenseCategory::ELECTRICITY),
            'internet' => $getCategorySum(ExpenseCategory::INTERNET),
            'rent' => $getCategorySum(ExpenseCategory::RENT),
            'water' => $getCategorySum(ExpenseCategory::WATER),
            'other' => $getCategorySum(ExpenseCategory::OTHER),
        ];

        // 4. Charts - last 6 months
        $categories = [];
        $incomeData = [];
        $expenseData = [];

        for ($i = 5; $i >= 0; $i--) {
            $mStart = $now->copy()->subMonths($i)->startOfMonth();
            $mEnd = $now->copy()->subMonths($i)->endOfMonth();

            $categories[] = $mStart->format('M');
            $incomeData[] = (float) Payment::whereBetween('created_at', [$mStart, $mEnd])->sum('amount');
            $expenseData[] = (float) Expense::whereBetween('expense_date', [$mStart->format('Y-m-d'), $mEnd->format('Y-m-d')])->sum('amount');
        }

        return [
            'current_month' => $now->format('F Y'),
            'period' => [
                'from' => $startMtd->format('Y-m-d'),
                'to' => $endMtd->format('Y-m-d'),
            ],
            'kpis' => [
                'total_invoiced_mtd' => $totalInvoicedMtd,
                'total_collected_mtd' => $totalCollectedMtd,
                'total_expenses_mtd' => $totalExpensesMtd,
                'net_profit_mtd' => $netProfitMtd,
                'collection_rate_mtd' => $collectionRateMtd,
                'outstanding_balance_all_time' => $outstandingAllTime,
                'overdue_amount_all_time' => $overdueAmount,
            ],
            'invoices_summary_mtd' => $invoicesSummaryMtd,
            'expenses_breakdown_mtd' => $expensesBreakdownMtd,
            'charts' => [
                'categories' => $categories,
                'series' => [
                    ['name' => 'Income', 'data' => $incomeData],
                    ['name' => 'Expenses', 'data' => $expenseData],
                ],
            ],
        ];
    }

    public function getIncomeExpense(?string $range): array
    {
        [$start, $end, $prevStart, $prevEnd] = $this->parseDateRange($range);

        $totalIncome = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $prevIncome = (float) Payment::whereBetween('created_at', [$prevStart, $prevEnd])->sum('amount');
        $incomeChangePct = $prevIncome > 0 ? round((($totalIncome - $prevIncome) / $prevIncome) * 100, 1) : 0.0;

        $totalExpenses = (float) Expense::whereBetween('expense_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->sum('amount');
        $prevExpenses = (float) Expense::whereBetween('expense_date', [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')])->sum('amount');
        $expenseChangePct = $prevExpenses > 0 ? round((($totalExpenses - $prevExpenses) / $prevExpenses) * 100, 1) : 0.0;

        $series = [];
        $curr = $start->copy()->startOfMonth();
        while ($curr->lte($end)) {
            $mStart = $curr->copy()->startOfMonth();
            $mEnd = $curr->copy()->endOfMonth();

            $inc = (float) Payment::whereBetween('created_at', [$mStart, $mEnd])->sum('amount');
            $exp = (float) Expense::whereBetween('expense_date', [$mStart->format('Y-m-d'), $mEnd->format('Y-m-d')])->sum('amount');

            $series[] = [
                'period' => $curr->format('M Y'),
                'income' => $inc,
                'expenses' => $exp,
            ];

            $curr->addMonth();
        }

        return [
            'total_income' => $totalIncome,
            'income_change_pct' => $incomeChangePct,
            'total_expenses' => $totalExpenses,
            'expense_change_pct' => $expenseChangePct,
            'series' => $series,
        ];
    }

    public function getPatients(?string $range): array
    {
        [$start, $end] = $this->parseDateRange($range);

        $appts = Appointment::whereBetween('scheduled_at', [$start, $end])->get();
        $getStatusCount = fn (AppointmentStatus $st) => $appts->filter(fn ($a) => ($a->status instanceof AppointmentStatus ? $a->status->value : $a->status) === $st->value)->count();

        return [
            'scheduled' => $getStatusCount(AppointmentStatus::SCHEDULED),
            'completed' => $getStatusCount(AppointmentStatus::COMPLETED),
            'no_show' => $getStatusCount(AppointmentStatus::NO_SHOW),
            'cancelled' => $getStatusCount(AppointmentStatus::CANCELLED),
        ];
    }

    public function getCashflow(?string $range): array
    {
        [$start, $end, $prevStart, $prevEnd] = $this->parseDateRange($range);

        $total = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $prevTotal = (float) Payment::whereBetween('created_at', [$prevStart, $prevEnd])->sum('amount');
        $changePct = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0.0;

        $points = [];
        $diffDays = $start->diffInDays($end);
        if ($diffDays <= 35) {
            $curr = $start->copy()->startOfDay();
            while ($curr->lte($end)) {
                $dayStart = $curr->copy()->startOfDay();
                $dayEnd = $curr->copy()->endOfDay();
                $amt = (float) Payment::whereBetween('created_at', [$dayStart, $dayEnd])->sum('amount');

                $points[] = [
                    'date' => $curr->format('Y-m-d'),
                    'amount' => $amt,
                ];
                $curr->addDay();
            }
        } else {
            $curr = $start->copy()->startOfMonth();
            while ($curr->lte($end)) {
                $mStart = $curr->copy()->startOfMonth();
                $mEnd = $curr->copy()->endOfMonth();
                $amt = (float) Payment::whereBetween('created_at', [$mStart, $mEnd])->sum('amount');

                $points[] = [
                    'date' => $curr->format('Y-m-d'),
                    'amount' => $amt,
                ];
                $curr->addMonth();
            }
        }

        return [
            'total' => $total,
            'change_pct' => $changePct,
            'points' => $points,
        ];
    }

    public function getExpenseBreakdown(?string $range): array
    {
        [$start, $end] = $this->parseDateRange($range);

        $expenses = Expense::whereBetween('expense_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->get();
        $getSum = fn (ExpenseCategory $cat) => (float) $expenses->filter(fn ($e) => ($e->category instanceof ExpenseCategory ? $e->category->value : $e->category) === $cat->value)->sum('amount');

        $elec = $getSum(ExpenseCategory::ELECTRICITY);
        $net = $getSum(ExpenseCategory::INTERNET);
        $rent = $getSum(ExpenseCategory::RENT);
        $water = $getSum(ExpenseCategory::WATER);
        $other = $getSum(ExpenseCategory::OTHER);
        $total = $elec + $net + $rent + $water + $other;

        return [
            'electricity' => $elec,
            'internet' => $net,
            'rent' => $rent,
            'water' => $water,
            'other' => $other,
            'total' => $total,
        ];
    }

    public function getInvoiceStatus(?string $range): array
    {
        [$start, $end] = $this->parseDateRange($range);

        $invoices = Invoice::whereBetween('created_at', [$start, $end])->get();
        $getAmount = fn (InvoiceStatus $st) => (float) $invoices
            ->filter(fn ($i) => ($i->status instanceof InvoiceStatus ? $i->status->value : $i->status) === $st->value)
            ->sum(fn ($i) => (float) $i->total_amount);

        $paid = $getAmount(InvoiceStatus::PAID);
        $partial = $getAmount(InvoiceStatus::PARTIAL);
        $unpaid = $getAmount(InvoiceStatus::UNPAID);
        $total = (float) $invoices->sum(fn ($i) => (float) $i->total_amount);

        return [
            'paid' => round($paid, 2),
            'partial' => round($partial, 2),
            'unpaid' => round($unpaid, 2),
            'total' => round($total, 2),
        ];
    }
}

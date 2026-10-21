<?php

namespace App\Services;

use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\AppointmentStatus;
use App\Enums\ExpenseCategory;
use App\Enums\InvoiceStatus;
use App\Enums\RefundReasonCode;
use App\Models\Appointment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Billing\InvoiceFinancials;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * Percent change versus the previous period.
     * A zero baseline with a positive current total is growth, not 0%.
     */
    private function percentChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

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
        $totalRefundedMtd = (float) Refund::whereBetween('refunded_at', [$startMtd, $endMtd])->sum('amount');
        $refundsCountMtd = Refund::whereBetween('refunded_at', [$startMtd, $endMtd])->count();
        $netCollectedMtd = $totalCollectedMtd - $totalRefundedMtd;
        $totalExpensesMtd = (float) Expense::whereBetween('expense_date', [$startMtd->format('Y-m-d'), $endMtd->format('Y-m-d')])->sum('amount');
        $netProfitMtd = $netCollectedMtd - $totalExpensesMtd;
        $collectionRateMtd = $totalInvoicedMtd > 0 ? round(($netCollectedMtd / $totalInvoicedMtd) * 100, 1) : 0.0;

        $outstandingPerformedAllTime = app(InvoiceItemStateSql::class)->sumOutstandingPerformedAllTime();

        $overdueAmount = (float) Invoice::whereIn('status', [InvoiceStatus::UNPAID->value, InvoiceStatus::PARTIAL->value])
            ->where('created_at', '<', $now->copy()->startOfDay())
            ->get()
            ->sum(fn (Invoice $invoice) => InvoiceFinancials::remainingAmount($invoice));

        // 2. Invoices summary MTD
        $invoicesMtd = Invoice::whereBetween('created_at', [$startMtd, $endMtd])->get();
        $invoicesSummaryMtd = [
            'total_invoices' => $invoicesMtd->count(),
            'paid_invoices' => $invoicesMtd->filter(fn ($i) => $i->status->value === InvoiceStatus::PAID->value)->count(),
            'partially_paid_invoices' => $invoicesMtd->filter(fn ($i) => $i->status->value === InvoiceStatus::PARTIAL->value)->count(),
            'unpaid_invoices' => $invoicesMtd->filter(fn ($i) => $i->status->value === InvoiceStatus::UNPAID->value)->count(),
            'refunded_invoices' => $invoicesMtd->filter(fn ($i) => $i->status->value === InvoiceStatus::REFUNDED->value)->count(),
        ];

        // 3. Expenses breakdown MTD
        $expensesMtd = Expense::whereBetween('expense_date', [$startMtd->format('Y-m-d'), $endMtd->format('Y-m-d')])->get();
        $getCategorySum = fn (ExpenseCategory $cat) => (float) $expensesMtd->filter(fn ($e) => ($e->category instanceof ExpenseCategory ? $e->category->value : $e->category) === $cat->value)->sum('amount');

        $expensesBreakdownMtd = [
            'electricity' => $getCategorySum(ExpenseCategory::ELECTRICITY),
            'internet' => $getCategorySum(ExpenseCategory::INTERNET),
            'rent' => $getCategorySum(ExpenseCategory::RENT),
            'water' => $getCategorySum(ExpenseCategory::WATER),
            'salary' => $getCategorySum(ExpenseCategory::SALARY),
            'lab' => $getCategorySum(ExpenseCategory::LAB),
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
            $incomeData[] = $this->netCollectedBetween($mStart, $mEnd);
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
                'total_refunded_mtd' => $totalRefundedMtd,
                'refunds_count_mtd' => $refundsCountMtd,
                'net_collected_mtd' => $netCollectedMtd,
                'total_expenses_mtd' => $totalExpensesMtd,
                'net_profit_mtd' => $netProfitMtd,
                'collection_rate_mtd' => $collectionRateMtd,
                'outstanding_performed_all_time' => $outstandingPerformedAllTime,
                'overdue_amount_all_time' => $overdueAmount,
            ],
            'refunds_by_reason_mtd' => $this->refundsByReason($startMtd, $endMtd),
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

        $grossIncome = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $refunded = (float) Refund::whereBetween('refunded_at', [$start, $end])->sum('amount');
        $refundsCount = Refund::whereBetween('refunded_at', [$start, $end])->count();
        $totalIncome = $grossIncome - $refunded;
        $prevIncome = $this->netCollectedBetween($prevStart, $prevEnd);
        $incomeChangePct = $this->percentChange($totalIncome, $prevIncome);

        $totalExpenses = (float) Expense::whereBetween('expense_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->sum('amount');
        $prevExpenses = (float) Expense::whereBetween('expense_date', [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')])->sum('amount');
        $expenseChangePct = $this->percentChange($totalExpenses, $prevExpenses);

        $series = [];
        $curr = $start->copy()->startOfMonth();
        while ($curr->lte($end)) {
            $mStart = $curr->copy()->startOfMonth();
            $mEnd = $curr->copy()->endOfMonth();

            $inc = $this->netCollectedBetween($mStart, $mEnd);
            $exp = (float) Expense::whereBetween('expense_date', [$mStart->format('Y-m-d'), $mEnd->format('Y-m-d')])->sum('amount');
            $refunds = $this->refundedBetween($mStart, $mEnd);

            $series[] = [
                'period' => $curr->format('M Y'),
                'income' => $inc,
                'expenses' => $exp,
                'refunds' => $refunds,
            ];

            $curr->addMonth();
        }

        return [
            'total_income' => $totalIncome,
            'gross_income' => $grossIncome,
            'total_refunded' => $refunded,
            'refunds_count' => $refundsCount,
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
        $getStatusCount = fn (AppointmentStatus $st) => $appts->filter(fn ($a) => $a->status === $st)->count();

        return [
            'scheduled' => $getStatusCount(AppointmentStatus::SCHEDULED),
            'checked_in' => $getStatusCount(AppointmentStatus::CHECKED_IN),
            'in_progress' => $getStatusCount(AppointmentStatus::IN_PROGRESS),
            'completed' => $getStatusCount(AppointmentStatus::COMPLETED),
            'no_show' => $getStatusCount(AppointmentStatus::NO_SHOW),
            'cancelled' => $getStatusCount(AppointmentStatus::CANCELLED),
        ];
    }

    public function getCashflow(?string $range): array
    {
        [$start, $end, $prevStart, $prevEnd] = $this->parseDateRange($range);

        $total = $this->netCollectedBetween($start, $end);
        $prevTotal = $this->netCollectedBetween($prevStart, $prevEnd);
        $changePct = $this->percentChange($total, $prevTotal);

        $points = [];
        $diffDays = $start->diffInDays($end);
        if ($diffDays <= 35) {
            $curr = $start->copy()->startOfDay();
            while ($curr->lte($end)) {
                $dayStart = $curr->copy()->startOfDay();
                $dayEnd = $curr->copy()->endOfDay();
                $amt = $this->netCollectedBetween($dayStart, $dayEnd);

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
                $amt = $this->netCollectedBetween($mStart, $mEnd);

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
        $salary = $getSum(ExpenseCategory::SALARY);
        $lab = $getSum(ExpenseCategory::LAB);
        $other = $getSum(ExpenseCategory::OTHER);
        $total = $elec + $net + $rent + $water + $salary + $lab + $other;

        return [
            'electricity' => $elec,
            'internet' => $net,
            'rent' => $rent,
            'water' => $water,
            'salary' => $salary,
            'lab' => $lab,
            'other' => $other,
            'total' => $total,
        ];
    }

    public function getInvoiceStatus(?string $range): array
    {
        [$start, $end] = $this->parseDateRange($range);

        $invoices = Invoice::whereBetween('created_at', [$start, $end])->get();
        $getAmount = fn (InvoiceStatus $st) => (float) $invoices
            ->filter(fn ($i) => $i->status === $st)
            ->sum(fn ($i) => (float) $i->total_amount);

        $paid = $getAmount(InvoiceStatus::PAID);
        $partial = $getAmount(InvoiceStatus::PARTIAL);
        $unpaid = $getAmount(InvoiceStatus::UNPAID);
        $refunded = $getAmount(InvoiceStatus::REFUNDED);
        $total = (float) $invoices->sum(fn ($i) => (float) $i->total_amount);

        return [
            'paid' => round($paid, 2),
            'partial' => round($partial, 2),
            'unpaid' => round($unpaid, 2),
            'refunded' => round($refunded, 2),
            'total' => round($total, 2),
        ];
    }

    public function getRefundBreakdown(?string $range): array
    {
        [$start, $end] = $this->parseDateRange($range);

        $breakdown = $this->refundsByReason($start, $end);
        $totalAmount = array_sum($breakdown);
        $count = Refund::whereBetween('refunded_at', [$start, $end])->count();

        return [
            'total_amount' => round((float) $totalAmount, 2),
            'total_count' => $count,
            'by_reason' => $breakdown,
        ];
    }

    private function netCollectedBetween(Carbon $start, Carbon $end): float
    {
        $collected = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $refunded = $this->refundedBetween($start, $end);

        return round($collected - $refunded, 2);
    }

    private function refundedBetween(Carbon $start, Carbon $end): float
    {
        return round((float) Refund::whereBetween('refunded_at', [$start, $end])->sum('amount'), 2);
    }

    /**
     * @return array<string, float>
     */
    private function refundsByReason(Carbon $start, Carbon $end): array
    {
        $refunds = Refund::whereBetween('refunded_at', [$start, $end])->get();
        $breakdown = [];

        foreach (RefundReasonCode::ordered() as $reason) {
            $breakdown[$reason->value] = round(
                (float) $refunds->filter(
                    fn (Refund $refund) => $refund->reason_code->value === $reason->value
                )->sum('amount'),
                2
            );
        }

        return $breakdown;
    }
}

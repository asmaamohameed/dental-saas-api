<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getFinancialAnalytics(User $user): array
    {
        // Resolve all tenant IDs belonging to this Owner
        $ownerTenantIds = User::withoutGlobalScope('tenant')
            ->where('email', $user->email)
            ->where('role', UserRole::OWNER)
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($ownerTenantIds)) {
            $ownerTenantIds = [$user->tenant_id];
        }

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth()->toDateTimeString();
        $today = $now->toDateString();
        $currentMonthFormatted = '"'.$now->format('F Y').'"';

        // 1. MTD Invoices Aggregation (from 1st of current month to current day)
        $paymentsSubqueryMTD = DB::table('payments')
            ->select('invoice_id', DB::raw('SUM(amount) as paid_amount'))
            ->whereNull('deleted_at')
            ->groupBy('invoice_id');

        $mtdInvoiceStats = DB::table('invoices')
            ->leftJoinSub($paymentsSubqueryMTD, 'payments_summary', function ($join) {
                $join->on('invoices.id', '=', 'payments_summary.invoice_id');
            })
            ->whereIn('invoices.tenant_id', $ownerTenantIds)
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', '!=', 'cancelled')
            ->where('invoices.created_at', '>=', $startOfMonth)
            ->selectRaw("
                COUNT(invoices.id) as total_invoices,
                COALESCE(SUM(CASE WHEN invoices.status = 'paid' THEN 1 ELSE 0 END), 0) as paid_invoices,
                COALESCE(SUM(CASE WHEN invoices.status = 'partial' THEN 1 ELSE 0 END), 0) as partially_paid_invoices,
                COALESCE(SUM(CASE WHEN invoices.status = 'unpaid' THEN 1 ELSE 0 END), 0) as unpaid_invoices,
                COALESCE(SUM(invoices.total_amount), 0) as total_invoiced,
                COALESCE(SUM(COALESCE(payments_summary.paid_amount, 0)), 0) as total_collected
            ")
            ->first();

        // Direct MTD payments collected in current month across all invoices
        $mtdPaymentsSum = DB::table('payments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $totalInvoicedMTD = (float) ($mtdInvoiceStats->total_invoiced ?? 0);
        $totalCollectedMTD = (float) $mtdPaymentsSum;

        // 2. All-Time Outstanding Balance & Overdue Amount
        $paymentsSubqueryAllTime = DB::table('payments')
            ->select('invoice_id', DB::raw('SUM(amount) as paid_amount'))
            ->whereNull('deleted_at')
            ->groupBy('invoice_id');

        $allTimeInvoiceStats = DB::table('invoices')
            ->leftJoinSub($paymentsSubqueryAllTime, 'payments_summary', function ($join) {
                $join->on('invoices.id', '=', 'payments_summary.invoice_id');
            })
            ->whereIn('invoices.tenant_id', $ownerTenantIds)
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', '!=', 'cancelled')
            ->selectRaw("
                COALESCE(SUM(GREATEST(0, invoices.total_amount - COALESCE(payments_summary.paid_amount, 0))), 0) as outstanding_balance,
                COALESCE(SUM(
                    CASE 
                        WHEN invoices.due_date IS NOT NULL 
                             AND invoices.due_date < ? 
                             AND (invoices.total_amount - COALESCE(payments_summary.paid_amount, 0)) > 0 
                        THEN (invoices.total_amount - COALESCE(payments_summary.paid_amount, 0))
                        ELSE 0 
                    END
                ), 0) as overdue_amount
            ", [$today])
            ->first();

        $outstandingBalanceAllTime = (float) ($allTimeInvoiceStats->outstanding_balance ?? 0);
        $overdueAmountAllTime = (float) ($allTimeInvoiceStats->overdue_amount ?? 0);

        // 3. MTD Expenses Aggregation
        $expensesStats = DB::table('expenses')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->where('expense_date', '>=', $now->copy()->startOfMonth()->toDateString())
            ->selectRaw("
                COALESCE(SUM(amount), 0) as total_expenses,
                COALESCE(SUM(CASE WHEN category = 'electricity' THEN amount ELSE 0 END), 0) as electricity,
                COALESCE(SUM(CASE WHEN category = 'internet' THEN amount ELSE 0 END), 0) as internet,
                COALESCE(SUM(CASE WHEN category = 'rent' THEN amount ELSE 0 END), 0) as rent,
                COALESCE(SUM(CASE WHEN category = 'water' THEN amount ELSE 0 END), 0) as water,
                COALESCE(SUM(CASE WHEN category = 'other' THEN amount ELSE 0 END), 0) as other
            ")
            ->first();

        $totalExpensesMTD = (float) ($expensesStats->total_expenses ?? 0);
        $netProfitMTD = $totalCollectedMTD - $totalExpensesMTD;
        $collectionRateMTD = $totalInvoicedMTD > 0
            ? round(($totalCollectedMTD / $totalInvoicedMTD) * 100, 2)
            : 0;

        // 4. Daily Chart Series (Month-to-Date daily trend)
        $daysInMTD = $now->day;
        $chartDates = [];
        $chartInvoiced = [];
        $chartCollected = [];
        $chartExpenses = [];

        for ($d = 1; $d <= $daysInMTD; $d++) {
            $dateObj = $now->copy()->day($d);
            $dateStr = $dateObj->toDateString();
            $chartDates[] = $dateObj->format('d M');

            $invoicedDay = DB::table('invoices')
                ->whereIn('tenant_id', $ownerTenantIds)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->whereDate('created_at', $dateStr)
                ->sum('total_amount');

            $collectedDay = DB::table('payments')
                ->whereIn('tenant_id', $ownerTenantIds)
                ->whereNull('deleted_at')
                ->whereDate('created_at', $dateStr)
                ->sum('amount');

            $expensesDay = DB::table('expenses')
                ->whereIn('tenant_id', $ownerTenantIds)
                ->whereNull('deleted_at')
                ->whereDate('expense_date', $dateStr)
                ->sum('amount');

            $chartInvoiced[] = (float) $invoicedDay;
            $chartCollected[] = (float) $collectedDay;
            $chartExpenses[] = (float) $expensesDay;
        }

        return [
            'current_month' => $currentMonthFormatted,
            'period' => [
                'from' => $now->copy()->startOfMonth()->toDateString(),
                'to' => $today,
            ],
            'kpis' => [
                'total_invoiced_mtd' => $totalInvoicedMTD,
                'total_collected_mtd' => $totalCollectedMTD,
                'total_expenses_mtd' => $totalExpensesMTD,
                'net_profit_mtd' => (float) $netProfitMTD,
                'collection_rate_mtd' => $collectionRateMTD,
                'outstanding_balance_all_time' => $outstandingBalanceAllTime,
                'overdue_amount_all_time' => $overdueAmountAllTime,
            ],
            'invoices_summary_mtd' => [
                'total_invoices' => (int) ($mtdInvoiceStats->total_invoices ?? 0),
                'paid_invoices' => (int) ($mtdInvoiceStats->paid_invoices ?? 0),
                'partially_paid_invoices' => (int) ($mtdInvoiceStats->partially_paid_invoices ?? 0),
                'unpaid_invoices' => (int) ($mtdInvoiceStats->unpaid_invoices ?? 0),
            ],
            'expenses_breakdown_mtd' => [
                'electricity' => (float) ($expensesStats->electricity ?? 0),
                'internet' => (float) ($expensesStats->internet ?? 0),
                'rent' => (float) ($expensesStats->rent ?? 0),
                'water' => (float) ($expensesStats->water ?? 0),
                'other' => (float) ($expensesStats->other ?? 0),
            ],
            'charts' => [
                'categories' => $chartDates,
                'series' => [
                    [
                        'name' => 'Invoiced',
                        'data' => $chartInvoiced,
                    ],
                    [
                        'name' => 'Collected',
                        'data' => $chartCollected,
                    ],
                    [
                        'name' => 'Expenses',
                        'data' => $chartExpenses,
                    ],
                ],
            ],
        ];
    }

    public function getIncomeExpenseAnalytics(User $user, string $range = 'this_month'): array
    {
        $ownerTenantIds = $this->getOwnerTenantIds($user);
        [$startDate, $endDate, $prevStartDate, $prevEndDate, $groupBy] = $this->parseRange($range);

        $totalIncome = (float) DB::table('payments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->sum('amount');

        $totalExpenses = (float) DB::table('expenses')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        $prevIncome = (float) DB::table('payments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$prevStartDate->toDateTimeString(), $prevEndDate->toDateTimeString()])
            ->sum('amount');

        $prevExpenses = (float) DB::table('expenses')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$prevStartDate->toDateString(), $prevEndDate->toDateString()])
            ->sum('amount');

        $incomeChangePct = $prevIncome > 0
            ? round((($totalIncome - $prevIncome) / $prevIncome) * 100, 1)
            : ($totalIncome > 0 ? 100.0 : 0.0);

        $expenseChangePct = $prevExpenses > 0
            ? round((($totalExpenses - $prevExpenses) / $prevExpenses) * 100, 1)
            : ($totalExpenses > 0 ? 100.0 : 0.0);

        $series = [];

        if ($groupBy === 'month') {
            $cursor = $startDate->copy()->startOfMonth();
            while ($cursor->lte($endDate)) {
                $mStart = $cursor->copy()->startOfMonth();
                $mEnd = $cursor->copy()->endOfMonth();
                $periodLabel = $cursor->format('M Y');

                $inc = (float) DB::table('payments')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('created_at', [$mStart->toDateTimeString(), $mEnd->toDateTimeString()])
                    ->sum('amount');

                $exp = (float) DB::table('expenses')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('expense_date', [$mStart->toDateString(), $mEnd->toDateString()])
                    ->sum('amount');

                $series[] = [
                    'period' => $periodLabel,
                    'income' => $inc,
                    'expenses' => $exp,
                ];

                $cursor->addMonth();
            }
        } else {
            $startDay = 1;
            $daysInMonth = $startDate->daysInMonth;
            $weekNum = 1;

            while ($startDay <= $daysInMonth) {
                $endDay = min($startDay + 6, $daysInMonth);
                $wStart = $startDate->copy()->day($startDay)->startOfDay();
                $wEnd = $startDate->copy()->day($endDay)->endOfDay();
                $periodLabel = "W{$weekNum} ({$wStart->format('d M')}-{$wEnd->format('d M')})";

                $inc = (float) DB::table('payments')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('created_at', [$wStart->toDateTimeString(), $wEnd->toDateTimeString()])
                    ->sum('amount');

                $exp = (float) DB::table('expenses')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('expense_date', [$wStart->toDateString(), $wEnd->toDateString()])
                    ->sum('amount');

                $series[] = [
                    'period' => $periodLabel,
                    'income' => $inc,
                    'expenses' => $exp,
                ];

                $startDay += 7;
                $weekNum++;
            }
        }

        return [
            'total_income' => $totalIncome,
            'income_change_pct' => $incomeChangePct,
            'total_expenses' => $totalExpenses,
            'expense_change_pct' => $expenseChangePct,
            'series' => $series,
        ];
    }

    public function getPatientAnalytics(User $user, string $range = 'this_month'): array
    {
        $ownerTenantIds = $this->getOwnerTenantIds($user);
        [$startDate, $endDate] = $this->parseRange($range);

        $counts = DB::table('appointments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereBetween('scheduled_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END), 0) as scheduled,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed,
                COALESCE(SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END), 0) as no_show,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled
            ")
            ->first();

        return [
            'scheduled' => (int) ($counts->scheduled ?? 0),
            'completed' => (int) ($counts->completed ?? 0),
            'no_show' => (int) ($counts->no_show ?? 0),
            'cancelled' => (int) ($counts->cancelled ?? 0),
        ];
    }

    public function getCashflowAnalytics(User $user, string $range = 'this_month'): array
    {
        $ownerTenantIds = $this->getOwnerTenantIds($user);
        [$startDate, $endDate, $prevStartDate, $prevEndDate, $groupBy] = $this->parseRange($range);

        $totalCash = (float) DB::table('payments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->sum('amount');

        $prevTotalCash = (float) DB::table('payments')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$prevStartDate->toDateTimeString(), $prevEndDate->toDateTimeString()])
            ->sum('amount');

        $changePct = $prevTotalCash > 0
            ? round((($totalCash - $prevTotalCash) / $prevTotalCash) * 100, 1)
            : ($totalCash > 0 ? 100.0 : 0.0);

        $points = [];

        if ($groupBy === 'month') {
            $cursor = $startDate->copy()->startOfMonth();
            while ($cursor->lte($endDate)) {
                $mStart = $cursor->copy()->startOfMonth();
                $mEnd = $cursor->copy()->endOfMonth();
                $dateLabel = $cursor->format('M Y');

                $amt = (float) DB::table('payments')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('created_at', [$mStart->toDateTimeString(), $mEnd->toDateTimeString()])
                    ->sum('amount');

                $points[] = [
                    'date' => $dateLabel,
                    'amount' => $amt,
                ];

                $cursor->addMonth();
            }
        } else {
            $cursor = $startDate->copy()->startOfDay();
            while ($cursor->lte($endDate)) {
                $dStart = $cursor->copy()->startOfDay();
                $dEnd = $cursor->copy()->endOfDay();
                $dateLabel = $cursor->format('d M');

                $amt = (float) DB::table('payments')
                    ->whereIn('tenant_id', $ownerTenantIds)
                    ->whereNull('deleted_at')
                    ->whereBetween('created_at', [$dStart->toDateTimeString(), $dEnd->toDateTimeString()])
                    ->sum('amount');

                $points[] = [
                    'date' => $dateLabel,
                    'amount' => $amt,
                ];

                $cursor->addDay();
            }
        }

        return [
            'total' => $totalCash,
            'change_pct' => $changePct,
            'points' => $points,
        ];
    }

    public function getExpenseBreakdownAnalytics(User $user, string $range = 'this_month'): array
    {
        $ownerTenantIds = $this->getOwnerTenantIds($user);
        [$startDate, $endDate] = $this->parseRange($range);

        $stats = DB::table('expenses')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN category = 'electricity' THEN amount ELSE 0 END), 0) as electricity,
                COALESCE(SUM(CASE WHEN category = 'internet' THEN amount ELSE 0 END), 0) as internet,
                COALESCE(SUM(CASE WHEN category = 'rent' THEN amount ELSE 0 END), 0) as rent,
                COALESCE(SUM(CASE WHEN category = 'water' THEN amount ELSE 0 END), 0) as water,
                COALESCE(SUM(CASE WHEN category = 'other' THEN amount ELSE 0 END), 0) as other,
                COALESCE(SUM(amount), 0) as total
            ")
            ->first();

        return [
            'electricity' => (float) ($stats->electricity ?? 0),
            'internet' => (float) ($stats->internet ?? 0),
            'rent' => (float) ($stats->rent ?? 0),
            'water' => (float) ($stats->water ?? 0),
            'other' => (float) ($stats->other ?? 0),
            'total' => (float) ($stats->total ?? 0),
        ];
    }

    public function getInvoiceStatusAnalytics(User $user, string $range = 'this_month'): array
    {
        $ownerTenantIds = $this->getOwnerTenantIds($user);
        [$startDate, $endDate] = $this->parseRange($range);

        $stats = DB::table('invoices')
            ->whereIn('tenant_id', $ownerTenantIds)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as paid,
                COALESCE(SUM(CASE WHEN status = 'partial' THEN total_amount ELSE 0 END), 0) as partial,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total_amount ELSE 0 END), 0) as unpaid,
                COALESCE(SUM(total_amount), 0) as total
            ")
            ->first();

        return [
            'paid' => (float) ($stats->paid ?? 0),
            'partial' => (float) ($stats->partial ?? 0),
            'unpaid' => (float) ($stats->unpaid ?? 0),
            'total' => (float) ($stats->total ?? 0),
        ];
    }

    private function getOwnerTenantIds(User $user): array
    {
        $ownerTenantIds = User::withoutGlobalScope('tenant')
            ->where('email', $user->email)
            ->where('role', UserRole::OWNER)
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($ownerTenantIds)) {
            $ownerTenantIds = [$user->tenant_id];
        }

        return $ownerTenantIds;
    }

    private function parseRange(string $range): array
    {
        $now = Carbon::now();

        switch ($range) {
            case 'last_month':
                $startDate = $now->copy()->subMonth()->startOfMonth();
                $endDate = $now->copy()->subMonth()->endOfMonth();
                $prevStartDate = $now->copy()->subMonths(2)->startOfMonth();
                $prevEndDate = $now->copy()->subMonths(2)->endOfMonth();
                $groupBy = 'day';
                break;
            case 'last_3_months':
                $startDate = $now->copy()->subMonths(2)->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                $prevStartDate = $now->copy()->subMonths(5)->startOfMonth();
                $prevEndDate = $now->copy()->subMonths(3)->endOfMonth();
                $groupBy = 'month';
                break;
            case 'last_6_months':
                $startDate = $now->copy()->subMonths(5)->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                $prevStartDate = $now->copy()->subMonths(11)->startOfMonth();
                $prevEndDate = $now->copy()->subMonths(6)->endOfMonth();
                $groupBy = 'month';
                break;
            case 'last_12_months':
                $startDate = $now->copy()->subMonths(11)->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                $prevStartDate = $now->copy()->subMonths(23)->startOfMonth();
                $prevEndDate = $now->copy()->subMonths(12)->endOfMonth();
                $groupBy = 'month';
                break;
            case 'this_month':
            default:
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfMonth();
                $prevStartDate = $now->copy()->subMonth()->startOfMonth();
                $prevEndDate = $now->copy()->subMonth()->endOfMonth();
                $groupBy = 'day';
                break;
        }

        return [$startDate, $endDate, $prevStartDate, $prevEndDate, $groupBy];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function financialAnalytics(Request $request): JsonResponse
    {
        $data = $this->dashboardService->getFinancialAnalytics($request->user());

        return $this->successResponse(
            $data,
            'Financial analytics retrieved successfully.'
        );
    }

    public function incomeExpense(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', 'this_month');
        $data = $this->dashboardService->getIncomeExpenseAnalytics($request->user(), $range);

        return $this->successResponse(
            $data,
            'Income & Expense analytics retrieved successfully.'
        );
    }

    public function patients(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', 'this_month');
        $data = $this->dashboardService->getPatientAnalytics($request->user(), $range);

        return $this->successResponse(
            $data,
            'Patient analytics retrieved successfully.'
        );
    }

    public function cashflow(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', 'this_month');
        $data = $this->dashboardService->getCashflowAnalytics($request->user(), $range);

        return $this->successResponse(
            $data,
            'Cashflow analytics retrieved successfully.'
        );
    }

    public function expenseBreakdown(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', 'this_month');
        $data = $this->dashboardService->getExpenseBreakdownAnalytics($request->user(), $range);

        return $this->successResponse(
            $data,
            'Expense breakdown retrieved successfully.'
        );
    }

    public function invoiceStatus(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', 'this_month');
        $data = $this->dashboardService->getInvoiceStatusAnalytics($request->user(), $range);

        return $this->successResponse(
            $data,
            'Invoice status analytics retrieved successfully.'
        );
    }
}

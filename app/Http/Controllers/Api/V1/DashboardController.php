<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function financialAnalytics(): JsonResponse
    {
        $data = $this->dashboardService->getFinancialAnalytics();

        return $this->successResponse($data, 'Financial analytics retrieved successfully.');
    }

    public function incomeExpense(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getIncomeExpense($range);

        return $this->successResponse($data, 'Income & expense analytics retrieved successfully.');
    }

    public function patients(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getPatients($range);

        return $this->successResponse($data, 'Patient analytics retrieved successfully.');
    }

    public function cashflow(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getCashflow($range);

        return $this->successResponse($data, 'Cashflow analytics retrieved successfully.');
    }

    public function expenseBreakdown(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getExpenseBreakdown($range);

        return $this->successResponse($data, 'Expense breakdown retrieved successfully.');
    }

    public function invoiceStatus(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getInvoiceStatus($range);

        return $this->successResponse($data, 'Invoice status analytics retrieved successfully.');
    }
}

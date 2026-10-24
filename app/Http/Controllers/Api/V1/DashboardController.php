<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\DashboardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly DashboardService $dashboardService) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $this->authorize('viewAny', Patient::class);

        $data = $this->dashboardService->getClinicOverview(
            $request->user(),
            $request->query('date_from'),
            $request->query('date_to'),
        );

        return $this->successResponse($data, 'Dashboard overview retrieved successfully.');
    }

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

    public function refundBreakdown(Request $request): JsonResponse
    {
        $range = $request->query('range');
        $data = $this->dashboardService->getRefundBreakdown($range);

        return $this->successResponse($data, 'Refund breakdown retrieved successfully.');
    }
}

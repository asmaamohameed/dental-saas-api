<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenseService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 50);

        $filters = $request->only(['category', 'date_from', 'date_to']);

        $expenses = $this->expenseService->list($filters, $perPage);

        return $this->paginatedResponse(
            $expenses,
            'Expenses retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|in:electricity,internet,rent,water,other',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $expense = $this->expenseService->create($validated, $request->user()->id);

        return $this->successResponse(
            $expense,
            'Expense recorded successfully.',
            201
        );
    }

    public function show(Expense $expense): JsonResponse
    {
        return $this->successResponse(
            $expense->load('creator'),
            'Expense details retrieved successfully.'
        );
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'sometimes|string|in:electricity,internet,rent,water,other',
            'title' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'expense_date' => 'sometimes|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $updated = $this->expenseService->update($expense, $validated);

        return $this->successResponse(
            $updated,
            'Expense updated successfully.'
        );
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenseService->delete($expense);

        return $this->successResponse(null, 'Expense deleted successfully.');
    }
}

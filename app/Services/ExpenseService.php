<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Expense::query()->with('creator');

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('expense_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('expense_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('expense_date', 'desc')->latest()->paginate($perPage);
    }

    public function create(array $data, string $userId): Expense
    {
        return Expense::create([
            ...$data,
            'created_by' => $userId,
        ])->load('creator');
    }

    public function update(Expense $expense, array $data): Expense
    {
        $expense->update($data);

        return $expense->fresh('creator');
    }

    public function delete(Expense $expense): bool
    {
        return (bool) $expense->delete();
    }
}

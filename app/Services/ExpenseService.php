<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

        return $query->latest('expense_date')->latest('created_at')->paginate($perPage);
    }

    public function create(array $data, string $userId): Expense
    {
        $data['created_by'] = $userId;

        return Expense::create($data);
    }

    public function delete(Expense $expense): bool
    {
        return (bool) $expense->delete();
    }
}

<?php

namespace App\Http\Resources\V1;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'category' => $this->category instanceof \BackedEnum ? $this->category->value : (string) $this->category,
            'title' => $this->title,
            'amount' => (float) $this->amount,
            'expense_date' => $this->expense_date instanceof \DateTimeInterface ? $this->expense_date->format('Y-m-d') : (string) $this->expense_date,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', function (?User $creator) {
                if (! $creator) {
                    return null;
                }

                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ];
            }),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

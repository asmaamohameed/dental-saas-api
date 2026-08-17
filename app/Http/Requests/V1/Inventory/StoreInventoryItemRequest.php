<?php

namespace App\Http\Requests\V1\Inventory;

use App\Models\InventoryItem;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryItem::class);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_items', 'name')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'unit' => ['required', 'string', 'max:50'],
            'minimum_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

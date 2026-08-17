<?php

namespace App\Http\Requests\V1\Inventory;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inventoryItem'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('inventory_items', 'name')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->ignore($this->route('inventory_item')),
            ],
            'unit' => ['sometimes', 'string', 'max:50'],
            'minimum_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

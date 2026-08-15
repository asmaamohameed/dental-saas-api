<?php

namespace App\Http\Requests\V1\Inventory;

use App\Models\InventoryTransaction;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [InventoryTransaction::class, $this->route('item')]);
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => [
                'required',
                'uuid',
                Rule::exists('inventory_items', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'type' => ['required', 'string', 'in:in,out'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

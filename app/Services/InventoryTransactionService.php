<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransactionService
{
    public function create(InventoryItem $item, array $data, string $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($item, $data, $performedBy) {
            /** @var InventoryItem $lockedItem */
            $lockedItem = InventoryItem::lockForUpdate()->find($item->id);

            $quantity = (string) $data['quantity'];
            $currentQuantity = (string) $lockedItem->current_quantity;
            $type = $data['type'];

            if ($type === 'out') {
                $newQuantity = bcsub($currentQuantity, $quantity, 2);

                if (bccomp($newQuantity, '0', 2) === -1) {
                    throw ValidationException::withMessages([
                        'quantity' => "Insufficient stock. Current quantity ({$currentQuantity}) is less than requested ({$quantity}).",
                    ]);
                }

                $lockedItem->update(['current_quantity' => $newQuantity]);
            } else {
                $newQuantity = bcadd($currentQuantity, $quantity, 2);
                $lockedItem->update(['current_quantity' => $newQuantity]);
            }

            /** @var InventoryTransaction $transaction */
            $transaction = $lockedItem->transactions()->create([
                ...$data,
                'performed_by' => $performedBy,
            ]);

            return $transaction->load(['performer', 'inventoryItem']);
        });
    }
}

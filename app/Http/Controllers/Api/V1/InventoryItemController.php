<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryItemListExpiry;
use App\Enums\InventoryItemListStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\V1\Inventory\UpdateInventoryItemRequest;
use App\Http\Resources\V1\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryItemController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $request->validate([
            'status' => ['sometimes', Rule::enum(InventoryItemListStatus::class)],
            'expiry' => ['sometimes', Rule::enum(InventoryItemListExpiry::class)],
        ]);

        $status = $request->enum('status', InventoryItemListStatus::class)
            ?? InventoryItemListStatus::Active;

        if ($status !== InventoryItemListStatus::Active) {
            $this->authorize('viewInactive', InventoryItem::class);
        }

        $lowStock = $request->boolean('low_stock');
        $expiry = $request->enum('expiry', InventoryItemListExpiry::class);

        $query = InventoryItem::query();

        // low_stock and expiry always scope to active items only, regardless of status.
        // expiry also requires quantity > 0 and a non-null expiry_date (via scopes).
        if ($lowStock || $expiry !== null) {
            $query->where('is_active', true);

            if ($expiry !== null) {
                $query->where('current_quantity', '>', 0);
            }
        } else {
            match ($status) {
                InventoryItemListStatus::Active => $query->where('is_active', true),
                InventoryItemListStatus::Inactive => $query->where('is_active', false),
                InventoryItemListStatus::All => null,
            };
        }

        if ($lowStock) {
            $query->lowStock();
        }

        if ($expiry !== null) {
            match ($expiry) {
                InventoryItemListExpiry::Expired => $query->expired(),
                InventoryItemListExpiry::Expiring => $query->expiringSoon(),
                InventoryItemListExpiry::Alert => $query->expiryAlert(),
            };
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        if ($expiry !== null) {
            $items = $query->orderBy('expiry_date')->paginate($perPage);
        } else {
            $items = $query->latest()->paginate($perPage);
        }

        return $this->paginatedResponse(
            InventoryItemResource::collection($items),
            'Inventory items retrieved successfully.'
        );
    }

    public function store(StoreInventoryItemRequest $request): JsonResponse
    {
        // authorize() handled inside StoreInventoryItemRequest
        $item = InventoryItem::create($request->validated());

        return $this->successResponse(
            new InventoryItemResource($item),
            'Inventory item created successfully.',
            201
        );
    }

    public function show(InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorize('view', $inventoryItem);

        return $this->successResponse(
            new InventoryItemResource($inventoryItem),
            'Inventory item retrieved successfully.'
        );
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        // authorize() handled inside UpdateInventoryItemRequest
        $inventoryItem->update($request->validated());

        return $this->successResponse(
            new InventoryItemResource($inventoryItem),
            'Inventory item updated successfully.'
        );
    }

    public function destroy(InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorize('delete', $inventoryItem);

        $inventoryItem->update(['is_active' => false]);

        return $this->successResponse(null, 'Inventory item deactivated successfully.');
    }

    public function restore(InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorize('restore', $inventoryItem);

        $inventoryItem->update(['is_active' => true]);

        return $this->successResponse(
            new InventoryItemResource($inventoryItem),
            'Inventory item reactivated successfully.'
        );
    }
}

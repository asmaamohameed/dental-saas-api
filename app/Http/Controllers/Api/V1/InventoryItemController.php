<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\V1\Inventory\UpdateInventoryItemRequest;
use App\Http\Resources\V1\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $query = InventoryItem::query()->where('is_active', true);

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $items = $query->latest()->paginate($perPage);

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

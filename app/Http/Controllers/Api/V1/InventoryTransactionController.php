<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Inventory\StoreInventoryTransactionRequest;
use App\Http\Resources\V1\InventoryTransactionResource;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\InventoryTransactionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryTransactionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly InventoryTransactionService $transactionService) {}

    public function index(InventoryItem $inventoryItem, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [InventoryTransaction::class, $inventoryItem]);

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $transactions = $inventoryItem->transactions()
            ->with('performer')
            ->latest('created_at')
            ->paginate($perPage);

        return $this->paginatedResponse(
            InventoryTransactionResource::collection($transactions),
            'Inventory transactions retrieved successfully.'
        );
    }

    public function store(StoreInventoryTransactionRequest $request, InventoryItem $inventoryItem): JsonResponse
    {
        $transaction = $this->transactionService->create(
            $inventoryItem,
            $request->validated(),
            $request->user()->id
        );

        return $this->successResponse(
            new InventoryTransactionResource($transaction),
            'Inventory transaction recorded successfully.',
            201
        );
    }
}

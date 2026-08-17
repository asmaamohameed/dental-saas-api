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

    public function index(InventoryItem $item, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [InventoryTransaction::class, $item]);

        $transactions = $item->transactions()
            ->with('performer')
            ->latest('created_at')
            ->paginate($request->integer('per_page', 15));

        return $this->paginatedResponse(
            InventoryTransactionResource::collection($transactions),
            'Inventory transactions retrieved successfully.'
        );
    }

    public function store(StoreInventoryTransactionRequest $request, InventoryItem $item): JsonResponse
    {
        $transaction = $this->transactionService->create(
            $item,
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

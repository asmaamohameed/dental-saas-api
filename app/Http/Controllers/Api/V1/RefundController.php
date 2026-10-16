<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Refund\StoreRefundRequest;
use App\Http\Resources\V1\RefundResource;
use App\Models\Invoice;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly RefundService $refundService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Refund::class);

        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 50);

        $filters = $request->only(['date_from', 'date_to', 'refunded_by', 'method', 'reason_code', 'search', 'patient_id']);

        $refunds = $this->refundService->list($filters, $perPage);
        $summary = $this->refundService->summary($filters);

        $response = $this->paginatedResponse(
            RefundResource::collection($refunds),
            'Refunds retrieved successfully.'
        );

        $payload = $response->getData(true);
        $payload['data']['summary'] = $summary;
        $response->setData($payload);

        return $response;
    }

    public function store(StoreRefundRequest $request, Invoice $invoice): JsonResponse
    {
        $refund = $this->refundService->create(
            $invoice,
            $request->validated(),
            $request->user()->id
        );

        return $this->successResponse(
            new RefundResource($refund),
            'Refund recorded successfully.',
            201
        );
    }

    public function show(Refund $refund): JsonResponse
    {
        $this->authorize('view', $refund);

        return $this->successResponse(
            new RefundResource($this->refundService->show($refund)),
            'Refund retrieved successfully.'
        );
    }
}

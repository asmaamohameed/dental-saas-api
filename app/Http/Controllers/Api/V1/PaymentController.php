<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Payment\StorePaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(Invoice $invoice): JsonResponse
    {
        $this->authorize('viewAny', [Payment::class, $invoice]);
        $payments = $invoice->payments()->with('receiver')->latest()->get();

        return $this->successResponse(
            PaymentResource::collection($payments),
            'Payments retrieved successfully.'
        );
    }

    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $payment = $this->paymentService->create(
            $invoice,
            $request->validated(),
            $request->user()->id
        );

        return $this->successResponse(
            new PaymentResource($payment),
            'Payment recorded successfully.',
            201
        );
    }

    public function show(Invoice $invoice, Payment $payment): JsonResponse
    {
        if ($payment->invoice_id !== $invoice->id) {
            return $this->errorResponse('Payment does not belong to this invoice.', 404);
        }

        $this->authorize('view', $payment);

        return $this->successResponse(
            new PaymentResource($payment->load('receiver')),
            'Payment retrieved successfully.'
        );
    }
}

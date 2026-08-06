<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Payment\StorePaymentRequest;
use App\Http\Requests\V1\Payment\UpdatePaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(Invoice $invoice): JsonResponse
    {
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

        return $this->successResponse(
            new PaymentResource($payment->load('receiver')),
            'Payment retrieved successfully.'
        );
    }

    public function update(UpdatePaymentRequest $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        if ($payment->invoice_id !== $invoice->id) {
            return $this->errorResponse('Payment does not belong to this invoice.', 404);
        }

        $updatedPayment = $this->paymentService->update($invoice, $payment, $request->validated());

        return $this->successResponse(
            new PaymentResource($updatedPayment),
            'Payment updated successfully.'
        );
    }

    public function destroy(Invoice $invoice, Payment $payment): JsonResponse
    {
        if ($payment->invoice_id !== $invoice->id) {
            return $this->errorResponse('Payment does not belong to this invoice.', 404);
        }

        $this->paymentService->delete($invoice, $payment);

        return $this->successResponse(null, 'Payment soft deleted successfully.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Refund\ListRefundsRequest;
use App\Http\Requests\V1\Refund\StoreRefundRequest;
use App\Http\Resources\V1\RefundResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(ListRefundsRequest $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = Payment::query()
            ->where('type', PaymentType::Refund->value)
            ->with([
                'receiver',
                'invoiceItem.patientTreatment.template',
                'invoice.patient',
            ]);

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->string('invoice_id'));
        }

        if ($request->filled('patient_id')) {
            $patientId = (string) $request->string('patient_id');
            $query->whereHas('invoice', fn ($invoice) => $invoice->where('patient_id', $patientId));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('paid_at', '>=', $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('paid_at', '<=', $request->string('date_to'));
        }

        $reason = $request->input('reason_code', $request->input('reason'));
        if (filled($reason)) {
            $query->where('reason', $reason);
        }

        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }

        if ($request->filled('refunded_by')) {
            $query->where('received_by', $request->string('refunded_by'));
        }

        if ($request->filled('search')) {
            $pattern = '%'.addcslashes(trim((string) $request->string('search')), '%_\\').'%';
            $query->where(function ($inner) use ($pattern) {
                $inner->where('invoice_id', 'ilike', $pattern)
                    ->orWhereHas('invoice.patient', fn ($patient) => $patient->where('full_name', 'ilike', $pattern));
            });
        }

        $summaryQuery = clone $query;
        $totalAmount = max(0, (float) (clone $summaryQuery)->sum('amount'));
        $totalCount = (clone $summaryQuery)->count();
        $performerIds = (clone $summaryQuery)->whereNotNull('received_by')->distinct()->pluck('received_by');
        $performers = User::query()
            ->whereIn('id', $performerIds)
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();

        $page = $query->orderByDesc('paid_at')->orderByDesc('id')->paginate($perPage);

        return $this->successResponse([
            'items' => RefundResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'total_amount' => round($totalAmount, 2),
                'total_count' => $totalCount,
                'performers' => $performers,
            ],
        ], 'Refunds retrieved successfully.');
    }

    public function store(StoreRefundRequest $request, Invoice $invoice): JsonResponse
    {
        $refund = $this->paymentService->refund(
            $invoice,
            $request->validated(),
            $request->user()->id,
        );

        return $this->successResponse(
            new RefundResource($refund),
            'Refund recorded successfully.',
            201
        );
    }
}

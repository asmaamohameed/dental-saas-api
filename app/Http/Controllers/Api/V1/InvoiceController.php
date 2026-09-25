<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Invoice\StoreInvoiceRequest;
use App\Http\Requests\V1\Invoice\UpdateInvoiceRequest;
use App\Http\Resources\V1\InvoiceListResource;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\PatientTreatment;
use App\Services\InvoiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 50);

        $filters = $request->only(['status', 'patient_id', 'date_from', 'date_to', 'search']);

        $invoices = $this->invoiceService->list($filters, $perPage);

        return $this->paginatedResponse(
            InvoiceListResource::collection($invoices),
            'Invoices retrieved successfully.'
        );
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->create(
            $request->validated(),
            $request->user()->id
        );

        return $this->successResponse(
            new InvoiceResource($invoice),
            'Invoice created successfully.',
            201
        );
    }

    public function storeFromTreatment(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $invoice = $this->invoiceService->createFromTreatment($patientTreatment, $request->user()->id);

        return $this->successResponse(
            new InvoiceResource($invoice),
            'Invoice created from treatment successfully.',
            201
        );
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->show($invoice);

        return $this->successResponse(
            new InvoiceResource($invoice),
            'Invoice retrieved successfully.'
        );
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        if ($request->has('items')) {
            $this->authorize('updateItems', $invoice);
        }

        $updatedInvoice = $this->invoiceService->update($invoice, $request->validated());

        return $this->successResponse(
            new InvoiceResource($updatedInvoice),
            'Invoice updated successfully.'
        );
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->invoiceService->delete($invoice);

        return $this->successResponse(null, 'Invoice soft deleted successfully.');
    }

    public function patientSummary(string $patientId): JsonResponse
    {
        $summary = $this->invoiceService->getPatientSummary($patientId);

        return $this->successResponse($summary, 'Patient invoice summary retrieved successfully.');
    }
}

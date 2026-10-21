<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InvoiceListResource;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
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

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->show($invoice);

        return $this->successResponse(
            new InvoiceResource($invoice),
            'Invoice retrieved successfully.'
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

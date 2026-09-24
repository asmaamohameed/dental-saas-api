<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Invoice\StoreInvoiceItemRequest;
use App\Http\Requests\V1\Invoice\UpdateInvoiceItemRequest;
use App\Http\Resources\V1\InvoiceItemResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Service;
use App\Services\InvoiceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function index(Invoice $invoice): JsonResponse
    {
        $this->authorize('viewAny', [InvoiceItem::class, $invoice]);

        $items = $invoice->items()->with(['service', 'patientTreatment.template'])->get();

        return $this->successResponse(
            InvoiceItemResource::collection($items),
            'Invoice items retrieved successfully.'
        );
    }

    public function store(StoreInvoiceItemRequest $request, Invoice $invoice): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cannot add items to a fully paid invoice.',
                ]);
            }

            $validated = $request->validated();
            if (! empty($validated['patient_treatment_id'])) {
                $treatment = PatientTreatment::with('template')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->findOrFail($validated['patient_treatment_id']);
                $validated['price'] = $validated['price'] ?? (float) $treatment->agreed_price;
                $validated['description'] ??= $treatment->template?->name_en ?: $treatment->template?->name_ar;
            } else {
                $service = Service::where('tenant_id', app(CurrentTenant::class)->id())
                    ->findOrFail($validated['service_id']);

                $validated['price'] = $service->is_other
                    ? (float) $validated['price']
                    : (float) $service->default_price;
            }

            $item = $lockedInvoice->items()->create($validated);

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;
            $lockedInvoice->update(['total_amount' => $newTotal]);

            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $item;
        });

        return $this->successResponse(
            new InvoiceItemResource($item->load(['service', 'patientTreatment.template'])),
            'Invoice item added successfully.',
            201
        );
    }

    public function update(UpdateInvoiceItemRequest $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        $updatedItem = DB::transaction(function () use ($request, $invoice, $item) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cannot modify items of a fully paid invoice.',
                ]);
            }

            $validated = $request->validated();

            if (array_key_exists('service_id', $validated) || array_key_exists('price', $validated)) {
                if (! empty($validated['patient_treatment_id'])) {
                    $treatment = PatientTreatment::where('tenant_id', app(CurrentTenant::class)->id())
                        ->findOrFail($validated['patient_treatment_id']);
                    $validated['price'] = $validated['price'] ?? (float) $treatment->agreed_price;
                } else {
                    $serviceId = $validated['service_id'] ?? $item->service_id;
                    $service = Service::where('tenant_id', app(CurrentTenant::class)->id())
                        ->findOrFail($serviceId);

                    if (! $service->is_other) {
                        $validated['price'] = (float) $service->default_price;
                    }
                }
            }

            $item->update($validated);

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;
            $totalPayments = $lockedInvoice->payments()->sum('amount');

            if (bccomp((string) $newTotal, (string) $totalPayments, 2) === -1) {
                throw ValidationException::withMessages([
                    'quantity' => 'New total cannot be less than paid amount.',
                ]);
            }

            $lockedInvoice->update(['total_amount' => $newTotal]);
            $this->invoiceService->recalculateStatus($lockedInvoice);

            return $item;
        });

        return $this->successResponse(
            new InvoiceItemResource($updatedItem->load(['service', 'patientTreatment.template'])),
            'Invoice item updated successfully.'
        );
    }

    public function destroy(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        $this->authorize('delete', $item);

        DB::transaction(function () use ($invoice, $item) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cannot delete items from a fully paid invoice.',
                ]);
            }

            if ($lockedInvoice->items()->count() <= 1) {
                throw ValidationException::withMessages([
                    'item' => 'Invoice must contain at least one item.',
                ]);
            }

            $item->delete();

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;
            $totalPayments = $lockedInvoice->payments()->sum('amount');

            if (bccomp((string) $newTotal, (string) $totalPayments, 2) === -1) {
                throw ValidationException::withMessages([
                    'item' => 'New total cannot be less than paid amount.',
                ]);
            }

            $lockedInvoice->update(['total_amount' => $newTotal]);
            $this->invoiceService->recalculateStatus($lockedInvoice);
        });

        return $this->successResponse(null, 'Invoice item deleted successfully.');
    }
}

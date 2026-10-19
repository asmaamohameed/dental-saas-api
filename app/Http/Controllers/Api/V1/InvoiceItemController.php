<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Actions\RepriceDoneItem;
use App\Billing\Actions\VoidInvoiceItem;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Invoice\StoreInvoiceItemRequest;
use App\Http\Requests\V1\Invoice\UpdateInvoiceItemRequest;
use App\Http\Requests\V1\InvoiceItem\RepriceDoneItemRequest;
use App\Http\Requests\V1\InvoiceItem\VoidInvoiceItemRequest;
use App\Http\Resources\V1\InvoiceItemResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\User;
use App\Support\Billing\InvoiceFinancials;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RecomputeInvoiceTotal $recomputeInvoiceTotal,
        private readonly InvoiceItemStateSql $invoiceItemStateSql,
        private readonly VoidInvoiceItem $voidInvoiceItem,
        private readonly RepriceDoneItem $repriceDoneItem,
    ) {}

    public function index(Invoice $invoice): JsonResponse
    {
        $this->authorize('viewAny', [InvoiceItem::class, $invoice]);

        $items = $invoice->items()->with(['patientTreatment.template'])->get();

        return $this->successResponse(
            InvoiceItemResource::collection($items),
            'Invoice items retrieved successfully.'
        );
    }

    public function store(StoreInvoiceItemRequest $request, Invoice $invoice): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
            InvoiceFinancials::assertMutable($lockedInvoice);

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
                $validated['price'] = $validated['price'] ?? $treatment->total_price;
                $validated['description'] ??= $treatment->template?->name_en ?: $treatment->template?->name_ar;
            }

            $item = $lockedInvoice->items()->create($validated);

            $this->recomputeInvoiceTotal->handle($lockedInvoice);

            return $item;
        });

        return $this->successResponse(
            new InvoiceItemResource($item->load(['patientTreatment.template'])),
            'Invoice item added successfully.',
            201
        );
    }

    public function update(UpdateInvoiceItemRequest $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        // TODO(billing-6b): block PUT changes on DONE lines; use reprice-done instead.

        $updatedItem = DB::transaction(function () use ($request, $invoice, $item) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
            InvoiceFinancials::assertMutable($lockedInvoice);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Cannot modify items of a fully paid invoice.',
                ]);
            }

            $validated = $request->validated();

            if (! empty($validated['patient_treatment_id'])) {
                $treatment = PatientTreatment::where('tenant_id', app(CurrentTenant::class)->id())
                    ->findOrFail($validated['patient_treatment_id']);
                $validated['price'] = $validated['price'] ?? $treatment->total_price;
            }

            $item->update($validated);

            $billableTotal = $this->invoiceItemStateSql->totalsForInvoice((string) $lockedInvoice->id)['total'];
            $totalPayments = $lockedInvoice->payments()->sum('amount');

            if (bccomp($billableTotal, (string) $totalPayments, 2) === -1) {
                throw ValidationException::withMessages([
                    'quantity' => 'New total cannot be less than paid amount.',
                ]);
            }

            $this->recomputeInvoiceTotal->handle($lockedInvoice);

            return $item;
        });

        return $this->successResponse(
            new InvoiceItemResource($updatedItem->load(['patientTreatment.template'])),
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
            InvoiceFinancials::assertMutable($lockedInvoice);

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

            $billableTotal = $this->invoiceItemStateSql->totalsForInvoice((string) $lockedInvoice->id)['total'];
            $totalPayments = $lockedInvoice->payments()->sum('amount');

            if (bccomp($billableTotal, (string) $totalPayments, 2) === -1) {
                throw ValidationException::withMessages([
                    'item' => 'New total cannot be less than paid amount.',
                ]);
            }

            $this->recomputeInvoiceTotal->handle($lockedInvoice);
        });

        return $this->successResponse(null, 'Invoice item deleted successfully.');
    }

    public function voidItem(VoidInvoiceItemRequest $request, InvoiceItem $item): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $updated = DB::transaction(function () use ($request, $item, $actor) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($item->invoice_id);

            return $this->voidInvoiceItem->handle(
                $lockedInvoice,
                $item,
                $actor,
                (string) $request->validated('reason'),
            );
        });

        return $this->successResponse(
            new InvoiceItemResource($updated->load(['patientTreatment.template'])),
            'Invoice item voided successfully.'
        );
    }

    public function repriceDone(RepriceDoneItemRequest $request, InvoiceItem $item): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $updated = DB::transaction(function () use ($request, $item, $actor) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($item->invoice_id);

            return $this->repriceDoneItem->handle(
                $lockedInvoice,
                $item,
                $actor,
                (string) $request->validated('price'),
                (string) $request->validated('reason'),
            );
        });

        return $this->successResponse(
            new InvoiceItemResource($updated->load(['patientTreatment.template'])),
            'Completed line repriced successfully.'
        );
    }
}

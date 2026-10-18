<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Actions\DeletePlannedItem;
use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Actions\RepriceDoneItem;
use App\Billing\Actions\VoidInvoiceItem;
use App\Billing\Domain\Exceptions\CannotMutateDoneItem;
use App\Billing\Domain\Exceptions\CannotMutateVoidedItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
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
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RecomputeInvoiceTotal $recomputeInvoiceTotal,
        private readonly DeletePlannedItem $deletePlannedItem,
        private readonly VoidInvoiceItem $voidInvoiceItem,
        private readonly RepriceDoneItem $repriceDoneItem,
        private readonly InvoiceItemFactsReader $factsReader,
        private readonly InvoiceItemStateResolver $stateResolver,
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
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            InvoiceClosedException::throwIf($lockedInvoice->status);

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

        $updatedItem = DB::transaction(function () use ($request, $invoice, $item) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            InvoiceClosedException::throwIf($lockedInvoice->status);
            $this->assertLineCanBeEdited($item);

            $validated = $request->validated();

            if (! empty($validated['patient_treatment_id'])) {
                $treatment = PatientTreatment::where('tenant_id', app(CurrentTenant::class)->id())
                    ->findOrFail($validated['patient_treatment_id']);
                $validated['price'] = $validated['price'] ?? $treatment->total_price;
            }

            $item->update($validated);
            $this->recomputeInvoiceTotal->handle($lockedInvoice);

            return $item;
        });

        return $this->successResponse(
            new InvoiceItemResource($updatedItem->load(['patientTreatment.template'])),
            'Invoice item updated successfully.'
        );
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        $this->authorize('delete', $item);

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        DB::transaction(function () use ($invoice, $item, $actor) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->deletePlannedItem->handle($lockedInvoice, $item, $actor);
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

    private function assertLineCanBeEdited(InvoiceItem $item): void
    {
        $state = $this->stateResolver->resolve($this->factsReader->read($item));

        if ($state === InvoiceItemState::DONE) {
            throw CannotMutateDoneItem::locked();
        }

        if ($state === InvoiceItemState::VOIDED) {
            throw CannotMutateVoidedItem::locked();
        }
    }
}

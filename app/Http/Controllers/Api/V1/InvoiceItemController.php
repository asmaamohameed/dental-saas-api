<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Invoice\StoreInvoiceItemRequest;
use App\Http\Requests\V1\Invoice\UpdateInvoiceItemRequest;
use App\Http\Resources\V1\InvoiceItemResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceItemController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function index(Invoice $invoice): JsonResponse
    {
        $items = $invoice->items()->with('service')->get();

        return $this->successResponse(
            InvoiceItemResource::collection($items),
            'Invoice items retrieved successfully.'
        );
    }

    public function store(StoreInvoiceItemRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return $this->errorResponse('Cannot add items to a fully paid invoice.', 422);
        }

        $item = DB::transaction(function () use ($request, $invoice) {
            $createdItem = $invoice->items()->create($request->validated());

            $newTotal = $invoice->items()->get()->sum(fn ($i) => $i->price * $i->quantity);
            $invoice->update(['total_amount' => $newTotal]);

            $this->invoiceService->recalculateStatus($invoice);

            return $createdItem->load('service');
        });

        return $this->successResponse(
            new InvoiceItemResource($item),
            'Invoice item added successfully.',
            201
        );
    }

    public function update(UpdateInvoiceItemRequest $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return $this->errorResponse('Cannot modify items of a fully paid invoice.', 422);
        }

        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        $updatedItem = DB::transaction(function () use ($request, $invoice, $item) {
            $item->update($request->validated());

            $newTotal = $invoice->items()->get()->sum(fn ($i) => $i->price * $i->quantity);
            $invoice->update(['total_amount' => $newTotal]);

            $this->invoiceService->recalculateStatus($invoice);

            return $item->load('service');
        });

        return $this->successResponse(
            new InvoiceItemResource($updatedItem),
            'Invoice item updated successfully.'
        );
    }

    public function destroy(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return $this->errorResponse('Cannot delete items from a fully paid invoice.', 422);
        }

        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        if ($invoice->items()->count() <= 1) {
            return $this->errorResponse('Invoice must contain at least one item.', 422);
        }

        DB::transaction(function () use ($invoice, $item) {
            $item->delete();

            $newTotal = $invoice->items()->get()->sum(fn ($i) => $i->price * $i->quantity);
            $invoice->update(['total_amount' => $newTotal]);

            $this->invoiceService->recalculateStatus($invoice);
        });

        return $this->successResponse(null, 'Invoice item deleted successfully.');
    }
}

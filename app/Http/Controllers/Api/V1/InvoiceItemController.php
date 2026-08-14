<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
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
        DB::beginTransaction();
        try {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                DB::rollBack();

                return $this->errorResponse('Cannot add items to a fully paid invoice.', 422);
            }

            $item = $lockedInvoice->items()->create($request->validated());

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;
            $lockedInvoice->update(['total_amount' => $newTotal]);

            $this->invoiceService->recalculateStatus($lockedInvoice);

            DB::commit();

            return $this->successResponse(
                new InvoiceItemResource($item->load('service')),
                'Invoice item added successfully.',
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(UpdateInvoiceItemRequest $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        DB::beginTransaction();
        try {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                DB::rollBack();

                return $this->errorResponse('Cannot modify items of a fully paid invoice.', 422);
            }

            $item->update($request->validated());

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;

            $totalPayments = $lockedInvoice->payments()->sum('amount');
            if ($newTotal < $totalPayments) {
                DB::rollBack();

                return $this->errorResponse('New total cannot be less than paid amount.', 422);
            }

            $lockedInvoice->update(['total_amount' => $newTotal]);
            $this->invoiceService->recalculateStatus($lockedInvoice);

            DB::commit();

            return $this->successResponse(
                new InvoiceItemResource($item->load('service')),
                'Invoice item updated successfully.'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($item->invoice_id !== $invoice->id) {
            return $this->errorResponse('Item does not belong to this invoice.', 404);
        }

        DB::beginTransaction();
        try {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
                DB::rollBack();

                return $this->errorResponse('Cannot delete items from a fully paid invoice.', 422);
            }

            if ($lockedInvoice->items()->count() <= 1) {
                DB::rollBack();

                return $this->errorResponse('Invoice must contain at least one item.', 422);
            }

            $item->delete();

            $newTotal = $lockedInvoice->items()->selectRaw('SUM(price * quantity) as total')->value('total') ?? 0;

            $totalPayments = $lockedInvoice->payments()->sum('amount');
            if ($newTotal < $totalPayments) {
                DB::rollBack();

                return $this->errorResponse('New total cannot be less than paid amount.', 422);
            }

            $lockedInvoice->update(['total_amount' => $newTotal]);
            $this->invoiceService->recalculateStatus($lockedInvoice);

            DB::commit();

            return $this->successResponse(null, 'Invoice item deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

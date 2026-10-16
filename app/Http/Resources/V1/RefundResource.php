<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => (float) $this->amount,
            'reason_code' => $this->reason_code->value,
            'reason_note' => $this->reason_note,
            'method' => $this->method,
            'refunded_by' => $this->refunded_by,
            'refunded_at' => $this->refunded_at->toISOString(),
            'refunded_by_user' => $this->whenLoaded('refundedBy', function (?User $user) {
                if (! $user) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }),
            'invoice' => $this->whenLoaded('invoice', function (Invoice $invoice) {
                $item = $invoice->relationLoaded('items') ? $invoice->items->first() : null;
                $treatment = $item instanceof InvoiceItem ? $item->patientTreatment : null;

                return [
                    'id' => $invoice->id,
                    'status' => $invoice->status->value,
                    'total_amount' => (float) $invoice->total_amount,
                    'patient_id' => $invoice->patient_id,
                    'patient_name' => $invoice->relationLoaded('patient')
                        ? $invoice->patient->full_name
                        : null,
                    'treatment_name' => $treatment?->template?->name_en
                        ?: $treatment?->template?->name_ar
                        ?: $item?->description,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

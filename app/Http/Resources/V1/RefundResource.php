<?php

namespace App\Http\Resources\V1;

use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refund rows are payments with type = refund. The JSON matches the existing refund screens.
 *
 * @mixin Payment
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $amount = max(0, (float) $this->amount);

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_item_id' => $this->invoice_item_id,
            'refunded_payment_id' => $this->refunded_payment_id,
            'amount' => $amount,
            'reason_code' => $this->reason,
            'reason_note' => $this->notes,
            'method' => $this->method,
            'refunded_by' => $this->received_by,
            'refunded_at' => $this->paid_at?->toISOString(),
            'refunded_by_user' => $this->whenLoaded('receiver', function (?User $receiver) {
                if (! $receiver) {
                    return null;
                }

                return [
                    'id' => $receiver->id,
                    'name' => $receiver->name,
                ];
            }),
            'invoice_item' => $this->whenLoaded('invoiceItem', function (?InvoiceItem $item) {
                if (! $item) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'subtotal' => (float) $item->subtotal,
                ];
            }),
            'invoice' => $this->whenLoaded('invoice', function () {
                $invoice = $this->invoice;
                $item = $this->relationLoaded('invoiceItem') ? $this->invoiceItem : null;

                return [
                    'id' => $invoice->id,
                    'status' => $invoice->status,
                    'total_amount' => max(0, (float) $invoice->total_amount),
                    'patient_id' => $invoice->patient_id,
                    'patient_name' => $invoice->relationLoaded('patient') ? $invoice->patient->full_name : null,
                    'treatment_name' => $this->treatmentName($item),
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function treatmentName(?InvoiceItem $item): ?string
    {
        if (! $item) {
            return null;
        }

        $treatment = $item->relationLoaded('patientTreatment') ? $item->patientTreatment : null;
        if ($treatment instanceof PatientTreatment && $treatment->relationLoaded('template') && $treatment->template) {
            return $treatment->template->name_en ?: $treatment->template->name_ar;
        }

        return $item->description;
    }
}

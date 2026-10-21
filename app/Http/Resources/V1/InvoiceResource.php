<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Support\Billing\InvoiceFinancials;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $refundDue = InvoiceFinancials::refundDue($this->resource);
        $netPaid = InvoiceFinancials::netPaid($this->resource);
        $refunded = $this->refundedAmount();
        $refundState = bccomp((string) $refunded, '0', 2) !== 1
            ? 'none'
            : (bccomp((string) $netPaid, '0', 2) === 1 ? 'partial' : 'full');

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'created_by' => $this->created_by,
            'total_amount' => (float) $this->total_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'paid_amount' => $this->paidAmount(),
            'net_paid' => $netPaid,
            'net_paid_amount' => $netPaid,
            'deducted_amount' => $this->deductedAmount(),
            'refunded_amount' => $refunded,
            'refund_state' => $refundState,
            'refund_due' => $refundDue,
            'has_refund_due' => bccomp((string) $refundDue, '0', 2) === 1,
            'status' => $this->status,
            'patient' => $this->whenLoaded('patient', function (Patient $patient) {
                return [
                    'id' => $patient->id,
                    'full_name' => $patient->full_name,
                    'phone' => $patient->phone,
                ];
            }),
            'appointment' => $this->whenLoaded('appointment', function (Appointment $appointment) {
                return [
                    'id' => $appointment->id,
                    'scheduled_at' => $appointment->scheduled_at,
                    'status' => $appointment->status,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function (User $creator) {
                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ];
            }),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'refund' => $this->whenLoaded('refunds', function () {
                $latest = $this->refunds
                    ->sortBy([['refunded_at', 'desc'], ['id', 'desc']])
                    ->first();

                return $latest ? new RefundResource($latest) : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

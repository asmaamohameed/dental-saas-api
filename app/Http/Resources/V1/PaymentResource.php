<?php

namespace App\Http\Resources\V1;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => (float) $this->amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'method' => $this->method,
            'received_by' => $this->received_by,
            'receiver' => $this->whenLoaded('receiver', function (?User $receiver) {
                if (! $receiver) {
                    return null;
                }

                return [
                    'id' => $receiver->id,
                    'name' => $receiver->name,
                ];
            }),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

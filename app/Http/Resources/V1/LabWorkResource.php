<?php

namespace App\Http\Resources\V1;

use App\Models\LabWork;
use App\Support\LabWorks\LabWorkTiming;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabWork
 */
class LabWorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expectedOn = $this->expected_on->format('Y-m-d');
        $receivedOn = $this->received_on?->format('Y-m-d');

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'lab_id' => $this->lab_id,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
            ]),
            'lab' => $this->whenLoaded('lab', fn () => [
                'id' => $this->lab->id,
                'name' => $this->lab->name,
                'is_active' => $this->lab->is_active,
            ]),
            'description' => $this->description,
            'notes' => $this->notes,
            'sent_on' => $this->sent_on->format('Y-m-d'),
            'expected_on' => $expectedOn,
            'received_on' => $receivedOn,
            'status' => $this->status->value,
            'timing' => LabWorkTiming::describe($this->status, $expectedOn, $receivedOn),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array<string, mixed> $resource
 */
class DoctorAvailabilityResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'state' => $this->resource['state'],
            'waiting_count' => $this->resource['waiting_count'],
            'in_visit' => $this->resource['in_visit'],
            'next_booking_at' => $this->resource['next_booking_at'],
            'works_today' => $this->resource['works_today'],
        ];
    }
}

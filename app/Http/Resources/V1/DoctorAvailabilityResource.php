<?php

namespace App\Http\Resources\V1;

use App\DataTransferObjects\DoctorAvailabilityRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read DoctorAvailabilityRow $resource
 */
class DoctorAvailabilityResource extends JsonResource
{
    public function __construct(DoctorAvailabilityRow $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

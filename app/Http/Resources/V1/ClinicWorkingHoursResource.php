<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array<string, array{is_open: bool, open: string, close: string}> $resource
 */
class ClinicWorkingHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'working_hours' => $this->resource,
            'timezone' => config('clinic.timezone'),
        ];
    }
}

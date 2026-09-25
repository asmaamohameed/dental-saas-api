<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\WorkingDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
        ];

        $day = $request->query('day');
        if (is_string($day) && in_array($day, WorkingDay::values(), true)) {
            $payload['works_on_day'] = in_array($day, $this->working_days ?? [], true);
        }

        return $payload;
    }
}

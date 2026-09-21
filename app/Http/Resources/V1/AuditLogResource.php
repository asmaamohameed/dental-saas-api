<?php

namespace App\Http\Resources\V1;

use App\Enums\AuditAction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $id
 * @property AuditAction $action
 * @property string $auditable_type
 * @property string $auditable_id
 * @property array|null $old_values
 * @property array|null $new_values
 * @property User|null $user
 * @property Carbon $created_at
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'auditable_type' => class_basename($this->auditable_type),
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}

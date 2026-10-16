<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\Roles\ClinicPermissionGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'role' => $this->role,
            'roles' => $this->currentClinicRoleNames(),
            'permissions' => app(ClinicPermissionGate::class)->forUser($this->resource),
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Models;

use App\Support\Roles\ClinicPermissionGate;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $role
 * @property array<string, bool> $permissions
 */
class ClinicRolePermission extends Model
{
    protected $fillable = [
        'tenant_id',
        'role',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        $flush = function (): void {
            if (app()->resolved(ClinicPermissionGate::class)) {
                app(ClinicPermissionGate::class)->flush();
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}

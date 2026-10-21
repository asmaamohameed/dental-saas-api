<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A clinic-saved lab work name, shown beside the built-in suggestions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $name_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LabWorkType extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    public const MAX_PER_CLINIC = 100;

    protected $fillable = [
        'name',
        'name_key',
    ];

    protected static function booted(): void
    {
        static::saving(function (LabWorkType $type): void {
            $type->name = self::displayName((string) $type->name);
            $type->name_key = self::nameKey($type->name);
        });
    }

    public static function displayName(string $name): string
    {
        return (string) Str::of($name)->squish();
    }

    public static function nameKey(string $name): string
    {
        return (string) Str::of($name)->squish()->lower();
    }
}

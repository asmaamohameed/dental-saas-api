<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $phone
 * @property string|null $contact_person
 * @property string|null $address
 * @property int|null $default_turnaround_days
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, LabWork> $labWorks
 */
class Lab extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'phone',
        'contact_person',
        'address',
        'default_turnaround_days',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_turnaround_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LabWork, $this>
     */
    public function labWorks(): HasMany
    {
        return $this->hasMany(LabWork::class);
    }
}

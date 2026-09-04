<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $full_name
 * @property string|null $phone
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property array<string, mixed>|null $medical_history
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Appointment> $appointments
 * @property-read Collection<int, ToothRecord> $toothRecords
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, XrayAttachment> $xrayAttachments
 */
class Patient extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'full_name',
        'phone',
        'date_of_birth',
        'gender',
        'medical_history',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'medical_history' => 'array',
        ];
    }

    // Relationships
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function toothRecords(): HasMany
    {
        return $this->hasMany(ToothRecord::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function xrayAttachments(): HasMany
    {
        return $this->hasMany(XrayAttachment::class);
    }
}

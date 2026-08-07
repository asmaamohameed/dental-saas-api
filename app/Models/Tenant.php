<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $subdomain
 * @property string $locale
 * @property TenantStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'subdomain',
        'locale',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    // Relationships
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function xrayAttachments(): HasMany
    {
        return $this->hasMany(XrayAttachment::class);
    }

    public function toothRecords(): HasMany
    {
        return $this->hasMany(ToothRecord::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}

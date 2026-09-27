<?php

namespace App\Models;

use App\Enums\ClinicTheme;
use App\Enums\TenantStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $name
 * @property string $subdomain
 * @property string $locale
 * @property TenantStatus $status
 * @property string|null $logo_path
 * @property ClinicTheme|null $theme
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $members_count
 * @property int|null $active_members_count
 * @property int|null $active_owner_count
 * @property int|null $doctor_count
 * @property int|null $inactive_membership_count
 * @property int|null $deactivated_owner_count
 * @property int|null $duplicate_name_count
 */
class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'subdomain',
        'locale',
        'status',
        'logo_path',
        'theme',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'theme' => ClinicTheme::class,
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

    public function members(): HasMany
    {
        return $this->hasMany(ClinicMember::class, 'clinic_id');
    }

    public function owners(): HasMany
    {
        return $this->members()->whereHas('roles', fn ($query) => $query->where('name', 'owner'));
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function treatmentTemplates(): HasMany
    {
        return $this->hasMany(TreatmentTemplate::class);
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

    public function logoPublicUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $publicFile = public_path($this->logo_path);
        if (is_file($publicFile)) {
            return url($this->logo_path);
        }

        if (Storage::disk('public')->exists($this->logo_path)) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return null;
    }
}

<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $doctor_id
 * @property string|null $booked_doctor_id
 * @property string|null $created_by
 * @property Carbon $scheduled_at
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $duration_minutes
 * @property AppointmentStatus $status
 * @property AppointmentType $appointment_type
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read User|null $doctor
 * @property-read User|null $creator
 */
class Appointment extends Model
{
    use Auditable, BelongsToTenant, HasFactory,HasUuids;

    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment): void {
            if (! $appointment->isDirty('status')) {
                return;
            }

            $now = now();

            if ($appointment->status === AppointmentStatus::CHECKED_IN && $appointment->checked_in_at === null) {
                $appointment->checked_in_at = $now;
            }

            if ($appointment->status === AppointmentStatus::IN_PROGRESS) {
                if ($appointment->started_at === null) {
                    $appointment->started_at = $now;
                }
                if ($appointment->checked_in_at === null) {
                    $appointment->checked_in_at = $now;
                }
            }
        });
    }

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'booked_doctor_id',
        'created_by',
        'scheduled_at',
        'duration_minutes',
        'status',
        'appointment_type',
        'notes',
        'checked_in_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => AppointmentStatus::class,
            'appointment_type' => AppointmentType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // Relationships
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function treatmentSessions(): HasMany
    {
        return $this->hasMany(TreatmentSession::class);
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

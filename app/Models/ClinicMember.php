<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $clinic_id
 * @property string $user_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clinic|null $clinic
 * @property-read User|null $user
 * @property-read Collection<int, Role> $roles
 */
class ClinicMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'clinic_id',
        'user_id',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $with = [
        'roles',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'clinic_member_role');
    }

    public function hasRole(string $roleName): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->first(fn (Role $role): bool => $role->name === $roleName) !== null;
        }

        return $this->roles()->where('name', $roleName)->exists();
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    /**
     * An owner may manage another member of the same clinic.
     * Another owner is off limits unless it is this same membership.
     */
    public function canManage(self $target): bool
    {
        if ($this->clinic_id !== $target->clinic_id || ! $this->isOwner()) {
            return false;
        }

        if ($target->isOwner() && $this->id !== $target->id) {
            return false;
        }

        return true;
    }
}

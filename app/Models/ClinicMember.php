<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClinicMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'clinic_id',
        'user_id',
    ];

    protected $with = [
        'roles',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'clinic_member_role');
    }

    public function hasRole(string $roleName): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role) => $role->name === $roleName);
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

<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can update the model status.
     */
    public function updateStatus(User $user, Appointment $appointment): bool
    {
        if ($user->role === UserRole::OWNER || $user->role === UserRole::RECEPTIONIST) {
            return true;
        }

        return $user->role === UserRole::DOCTOR && $appointment->doctor_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::OWNER;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Appointment $appointment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Appointment $appointment): bool
    {
        return false;
    }
}

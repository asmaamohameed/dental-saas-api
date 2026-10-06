<?php

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AppointmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyClinicRole([
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->tenant_id === $appointment->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::OWNER,
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyClinicRole([
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Appointment $appointment): bool|Response
    {
        if ($appointment->status === AppointmentStatus::COMPLETED) {
            return Response::deny('You cannot update a completed appointment.');
        }

        return $user->tenant_id === $appointment->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::OWNER,
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]);
    }

    /**
     * Role and tenant permission to attempt a status change (ignores transition validity).
     */
    public function updateStatusRole(User $user, Appointment $appointment, AppointmentStatus $newStatus): bool
    {
        if ($user->tenant_id !== $appointment->tenant_id) {
            return false;
        }

        if ($appointment->status === AppointmentStatus::COMPLETED) {
            return $user->isOwner();
        }

        if ($newStatus === AppointmentStatus::CHECKED_IN) {
            return $this->userCanCheckIn($user, $appointment);
        }

        if (in_array($newStatus, [AppointmentStatus::IN_PROGRESS, AppointmentStatus::COMPLETED], true)) {
            return $user->isOwner()
                || ($user->hasClinicRole(UserRole::DOCTOR) && $user->id === $appointment->doctor_id);
        }

        return $user->hasAnyClinicRole([
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]);
    }

    /**
     * Determine whether the user can update the model status.
     */
    public function updateStatus(User $user, Appointment $appointment, AppointmentStatus $newStatus): bool
    {
        if (! $appointment->status->canTransitionTo($newStatus)) {
            return false;
        }

        return $this->updateStatusRole($user, $appointment, $newStatus);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Appointment $appointment): bool|Response
    {
        if ($appointment->status === AppointmentStatus::COMPLETED) {
            return Response::deny('You cannot delete a completed appointment.');
        }

        return $user->tenant_id === $appointment->tenant_id
            && $user->isOwner();
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

    private function userCanCheckIn(User $user, Appointment $appointment): bool
    {
        return $user->isOwner()
            || $user->hasClinicRole(UserRole::RECEPTIONIST)
            || $user->hasClinicRole(UserRole::ASSISTANT)
            || ($user->hasClinicRole(UserRole::DOCTOR) && $user->id === $appointment->doctor_id);
    }

}

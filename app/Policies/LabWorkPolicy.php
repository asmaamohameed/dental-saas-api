<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LabWork;
use App\Models\User;

class LabWorkPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, LabWork $labWork): bool
    {
        return $user->tenant_id === $labWork->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->clinicCan('labWork:create', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }

    public function update(User $user, LabWork $labWork): bool
    {
        return $user->tenant_id === $labWork->tenant_id
            && $user->clinicCan('labWork:edit', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function markReceived(User $user, LabWork $labWork): bool
    {
        return $user->tenant_id === $labWork->tenant_id
            && $user->clinicCan('labWork:markReceived', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function cancel(User $user, LabWork $labWork): bool
    {
        return $user->tenant_id === $labWork->tenant_id
            && $user->clinicCan('labWork:cancel', false);
    }

    private function canView(User $user): bool
    {
        return $user->clinicCan('labWork:view', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }
}

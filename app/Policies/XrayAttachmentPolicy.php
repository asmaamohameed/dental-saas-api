<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use App\Models\XrayAttachment;

class XrayAttachmentPolicy
{
    public function viewAny(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->hasAnyClinicRole([UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT]);
    }

    public function create(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->hasAnyClinicRole([UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT]);
    }

    public function delete(User $user, XrayAttachment $xrayAttachment): bool
    {
        return $user->tenant_id === $xrayAttachment->tenant_id
            && $user->hasAnyClinicRole([UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT]);
    }
}

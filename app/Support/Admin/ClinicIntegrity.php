<?php

namespace App\Support\Admin;

use App\Enums\TenantStatus;
use App\Models\Tenant;

class ClinicIntegrity
{
    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    public static function issues(Tenant $clinic): array
    {
        $issues = [];
        $members = (int) ($clinic->members_count ?? 0);
        $activeOwners = (int) ($clinic->active_owner_count ?? 0);
        $doctors = (int) ($clinic->doctor_count ?? 0);
        $inactiveMemberships = (int) ($clinic->inactive_membership_count ?? 0);
        $deactivatedOwners = (int) ($clinic->deactivated_owner_count ?? 0);
        $duplicateNames = (int) ($clinic->duplicate_name_count ?? 0);
        $status = $clinic->status->value;

        if ($members === 0) {
            $issues[] = self::issue('no_members', 'warning', 'This clinic has no members.');
        }

        if ($activeOwners === 0) {
            $issues[] = self::issue('no_active_owner', 'critical', 'This clinic has no active owner.');
        }

        if ($activeOwners > 1) {
            $issues[] = self::issue('multiple_owners', 'info', 'This clinic has more than one active owner.');
        }

        if ($doctors === 0) {
            $issues[] = self::issue('no_doctors', 'warning', 'This clinic has no active doctors.');
        }

        if ($inactiveMemberships > 0) {
            $issues[] = self::issue('inactive_memberships', 'warning', 'This clinic has inactive memberships or deactivated member accounts.');
        }

        if ($deactivatedOwners > 0) {
            $issues[] = self::issue('deactivated_owner', 'critical', 'An owner account for this clinic is deactivated.');
        }

        if ($duplicateNames > 0) {
            $issues[] = self::issue('duplicate_name', 'info', 'Another clinic uses the same name. Clinic names are not required to be unique.');
        }

        if ($status === TenantStatus::INACTIVE->value && (int) ($clinic->active_members_count ?? 0) > 0) {
            $issues[] = self::issue('suspended_with_active_members', 'info', 'This clinic is suspended and still has active memberships. Memberships were not deleted.');
        }

        return $issues;
    }

    /**
     * @return array{code: string, severity: string, message: string}
     */
    private static function issue(string $code, string $severity, string $message): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
        ];
    }
}

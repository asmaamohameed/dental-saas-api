<?php

namespace App\Models;

/**
 * A clinic is a tenant. tenants.id is the clinic id used by clinic_members.clinic_id.
 *
 * @property int|null $members_count
 * @property int|null $active_members_count
 * @property int|null $active_owner_count
 * @property int|null $doctor_count
 * @property int|null $inactive_membership_count
 * @property int|null $deactivated_owner_count
 * @property int|null $duplicate_name_count
 * @property string|null $last_activity_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ClinicMember>|null $listedOwners
 */
class Clinic extends Tenant
{
    protected $table = 'tenants';
}

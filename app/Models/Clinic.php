<?php

namespace App\Models;

/**
 * A clinic is a tenant. tenants.id is the clinic id used by clinic_members.clinic_id.
 */
class Clinic extends Tenant
{
    protected $table = 'tenants';
}

<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;

trait PlatformAdminHelpers
{
    protected function actingAsPlatformAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);
        app(CurrentTenant::class)->clear();

        return $admin;
    }

    protected function makeClinicUser(Tenant $tenant, string $role = 'owner'): User
    {
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->{$role}()->create();
        app(CurrentTenant::class)->clear();

        return $user;
    }
}

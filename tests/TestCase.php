<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Create (or use) a tenant + user, bind CurrentTenant for factory setup,
     * and authenticate the user via Sanctum for the following requests.
     */
    protected function actingAsTenantUser(?Tenant $tenant = null, UserRole $role = UserRole::DOCTOR): User
    {
        $tenant ??= Tenant::factory()->create();

        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->{$role->value}()->create();

        Sanctum::actingAs($user);

        return $user;
    }
}

<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
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

    /**
     * Build an ISO-8601 UTC instant for a future clinic-local wall time (within default hours).
     */
    protected function scheduledAtWithinClinicHours(int $daysFromNow = 1, string $localTime = '10:00'): string
    {
        $tz = (string) config('clinic.timezone', 'Africa/Cairo');
        [$hours, $minutes] = array_map(intval(...), explode(':', $localTime));

        return Carbon::now($tz)
            ->addDays($daysFromNow)
            ->setTime($hours, $minutes, 0)
            ->utc()
            ->toIso8601String();
    }
}

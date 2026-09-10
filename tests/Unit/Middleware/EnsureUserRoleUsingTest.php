<?php

namespace Tests\Unit\Middleware;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserRole;
use PHPUnit\Framework\TestCase;

class EnsureUserRoleUsingTest extends TestCase
{
    public function test_using_builds_the_role_middleware_definition_string(): void
    {
        $this->assertSame(
            'role:owner,receptionist',
            EnsureUserRole::using(UserRole::OWNER, UserRole::RECEPTIONIST)
        );
    }

    public function test_using_with_a_single_role(): void
    {
        $this->assertSame('role:owner', EnsureUserRole::using(UserRole::OWNER));
    }
}

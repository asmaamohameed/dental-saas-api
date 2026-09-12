<?php

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_owner_role_helpers(): void
    {
        $this->assertTrue(UserRole::OWNER->isOwner());
        $this->assertFalse(UserRole::OWNER->isDoctor());
        $this->assertFalse(UserRole::OWNER->isReceptionist());
    }

    public function test_doctor_role_helpers(): void
    {
        $this->assertFalse(UserRole::DOCTOR->isOwner());
        $this->assertTrue(UserRole::DOCTOR->isDoctor());
        $this->assertFalse(UserRole::DOCTOR->isReceptionist());
    }

    public function test_receptionist_role_helpers(): void
    {
        $this->assertFalse(UserRole::RECEPTIONIST->isOwner());
        $this->assertFalse(UserRole::RECEPTIONIST->isDoctor());
        $this->assertTrue(UserRole::RECEPTIONIST->isReceptionist());
    }

    public function test_role_string_values(): void
    {
        $this->assertSame('owner', UserRole::OWNER->value);
        $this->assertSame('doctor', UserRole::DOCTOR->value);
        $this->assertSame('receptionist', UserRole::RECEPTIONIST->value);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = AdminUser::factory()->create([
            'email' => 'owner@example.com',
            'password_hash' => bcrypt('secret-password'),
        ]);

        $this->postJson('/admin/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('data.admin.id', $admin->id)
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonStructure(['data' => ['access_token', 'admin' => ['id', 'name', 'email']]]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => AdminUser::class,
            'name' => 'admin_api_token',
        ]);
    }

    public function test_admin_login_uses_custom_device_name_for_token(): void
    {
        $admin = AdminUser::factory()->create(['password_hash' => bcrypt('secret-password')]);

        $this->postJson('/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
            'device_name' => 'ipad',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'name' => 'ipad',
        ]);
    }

    public function test_admin_login_fails_with_wrong_password(): void
    {
        $admin = AdminUser::factory()->create(['password_hash' => bcrypt('correct-password')]);

        $this->postJson('/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_admin_login_fails_for_unknown_email(): void
    {
        $this->postJson('/admin/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = AdminUser::factory()->create([
            'password_hash' => bcrypt('secret-password'),
            'is_active' => false,
        ]);

        $this->postJson('/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_admin_login_requires_email_and_password(): void
    {
        $this->postJson('/admin/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}

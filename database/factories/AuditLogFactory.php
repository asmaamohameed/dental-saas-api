<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement(AuditAction::cases()),
            'auditable_type' => Patient::class,
            'auditable_id' => Str::uuid(),
            'old_values' => null,
            'new_values' => ['name' => fake()->name()],
        ];
    }
}

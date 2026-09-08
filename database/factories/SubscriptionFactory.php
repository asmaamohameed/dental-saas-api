<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_type' => fake()->randomElement(['monthly', 'yearly']),
            'status' => SubscriptionStatus::ACTIVE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'marked_paid_at' => null,
            'notes' => null,
        ];
    }
}

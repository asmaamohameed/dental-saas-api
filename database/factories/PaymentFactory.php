<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'invoice_id' => function (array $attributes) {
                return Invoice::factory()->create(['tenant_id' => $attributes['tenant_id']]);
            },
            'amount' => 100.00,
            'paid_at' => now(),
            'method' => 'cash',
            'received_by' => function (array $attributes) {
                return User::factory()->create(['tenant_id' => $attributes['tenant_id']]);
            },
            'notes' => fake()->sentence(),
        ];
    }
}

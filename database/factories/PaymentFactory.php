<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use App\Models\Invoice;
use App\Models\Payment;
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
            'invoice_id' => Invoice::factory(),
            'amount' => 50,
            'type' => PaymentType::Payment,
            'deduct_amount' => 0,
            'deduct_reason' => null,
            'paid_at' => now(),
            'method' => 'cash',
            'received_by' => User::factory()->receptionist(),
            'notes' => null,
        ];
    }

    public function refund(): static
    {
        return $this->state(fn () => [
            'type' => PaymentType::Refund,
            'amount' => 20,
            'deduct_amount' => 0,
            'deduct_reason' => null,
            'reason' => 'duplicate_payment',
        ]);
    }
}

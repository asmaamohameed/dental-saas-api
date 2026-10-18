<?php

namespace Database\Factories;

use App\Enums\RefundReasonCode;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => 50,
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT,
            'reason_note' => null,
            'method' => 'cash',
            'refunded_by' => User::factory()->receptionist(),
            'refunded_at' => now(),
        ];
    }
}

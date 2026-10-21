<?php

namespace Database\Factories;

use App\Enums\LabWorkStatus;
use App\Models\Lab;
use App\Models\LabWork;
use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabWork>
 */
class LabWorkFactory extends Factory
{
    protected $model = LabWork::class;

    public function definition(): array
    {
        $sent = now()->toDateString();

        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => Patient::factory(),
            'lab_id' => Lab::factory(),
            'description' => 'Zirconia crown',
            'notes' => null,
            'sent_on' => $sent,
            'expected_on' => $sent,
            'received_on' => null,
            'status' => LabWorkStatus::AT_LAB,
        ];
    }

    public function received(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => LabWorkStatus::RECEIVED,
                'received_on' => $attributes['expected_on'] ?? $attributes['sent_on'],
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => LabWorkStatus::CANCELLED,
            'received_on' => null,
        ]);
    }
}

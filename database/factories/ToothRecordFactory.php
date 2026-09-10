<?php

namespace Database\Factories;

use App\Enums\ToothTreatmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ToothRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToothRecord>
 */
class ToothRecordFactory extends Factory
{
    protected $model = ToothRecord::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory(),
            'recorded_by' => User::factory(),
            // FDI numbering: 11-18, 21-28, 31-38, 41-48
            'tooth_number' => fake()->randomElement([11, 16, 21, 26, 31, 36, 41, 46]),
            'condition' => fake()->randomElement(['healthy', 'decayed', 'filled', 'missing']),
            'treatment_status' => fake()->randomElement(ToothTreatmentStatus::cases()),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}

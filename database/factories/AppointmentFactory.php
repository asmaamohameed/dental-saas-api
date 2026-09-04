<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => User::factory()->doctor(),
            'created_by' => User::factory()->receptionist(),
            'scheduled_at' => fake()->dateTimeBetween('now', '+2 weeks'),
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60]),
            'status' => fake()->randomElement(AppointmentStatus::cases()),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}

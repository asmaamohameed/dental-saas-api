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
            'status' => AppointmentStatus::SCHEDULED,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::CHECKED_IN,
            'checked_in_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::IN_PROGRESS,
            'checked_in_at' => now()->subMinutes(15),
            'started_at' => now(),
        ]);
    }
}

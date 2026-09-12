<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Models\XrayAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<XrayAttachment>
 */
class XrayAttachmentFactory extends Factory
{
    protected $model = XrayAttachment::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory(),
            'file_url' => fake()->url().'/xray-'.fake()->uuid().'.jpg',
            'file_type' => fake()->randomElement(['image/jpeg', 'image/png', 'application/pdf']),
            'uploaded_by' => User::factory(),
        ];
    }
}

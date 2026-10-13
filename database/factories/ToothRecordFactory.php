<?php

namespace Database\Factories;

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

    /**
     * Legacy table only — odontogram state is derived from patient treatments.
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'appointment_id' => Appointment::factory(),
            'recorded_by' => User::factory(),
            'tooth_number' => 11,
            'condition' => 'healthy',
            'treatment_status' => 'planned',
            'notes' => null,
        ];
    }
}

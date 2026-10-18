<?php

namespace App\Http\Requests\V1\Appointment\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

trait ValidatesDoctorWorkingDay
{
    protected function rejectUnlessDoctorWorksOn(Validator $validator, Carbon $scheduledAt, string $doctorId): bool
    {
        $workingDays = User::query()->find($doctorId)?->working_days;
        if (! is_array($workingDays)) {
            return true;
        }

        $weekday = strtolower(
            $scheduledAt->copy()->timezone((string) config('clinic.timezone', 'Africa/Cairo'))->englishDayOfWeek
        );

        if (in_array($weekday, $workingDays, true)) {
            return true;
        }

        $validator->errors()->add(
            'scheduled_at',
            'The selected date is not one of this doctor\'s working days.'
        );

        return false;
    }
}

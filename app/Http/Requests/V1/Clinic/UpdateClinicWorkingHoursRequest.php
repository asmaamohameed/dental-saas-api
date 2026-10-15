<?php

namespace App\Http\Requests\V1\Clinic;

use App\Support\WorkingDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClinicWorkingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        foreach (WorkingDay::values() as $day) {
            $rules["{$day}"] = ['required', 'array'];
            $rules["{$day}.is_open"] = ['required', 'boolean'];
            $rules["{$day}.open"] = ['required', 'date_format:H:i'];
            $rules["{$day}.close"] = ['required', 'date_format:H:i'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $openDays = 0;

            foreach (WorkingDay::values() as $day) {
                if ($validator->errors()->has("{$day}.is_open") || $validator->errors()->has("{$day}.open") || $validator->errors()->has("{$day}.close")) {
                    continue;
                }

                $entry = $this->input($day);
                if (! is_array($entry)) {
                    continue;
                }

                if (! ($entry['is_open'] ?? false)) {
                    continue;
                }

                $openDays++;
                $open = $entry['open'] ?? '';
                $close = $entry['close'] ?? '';
                if ($open !== '' && $close !== '' && $close <= $open) {
                    $validator->errors()->add("{$day}.close", 'Close time must be after open time.');
                }
            }

            if ($openDays < 1) {
                $validator->errors()->add('working_hours', 'At least one day must be open.');
            }
        });
    }

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    public function normalizedSchedule(): array
    {
        $payload = [];
        foreach (WorkingDay::values() as $day) {
            $entry = $this->input($day);
            $payload[$day] = [
                'is_open' => (bool) ($entry['is_open'] ?? false),
                'open' => (string) ($entry['open'] ?? '09:00'),
                'close' => (string) ($entry['close'] ?? '21:00'),
            ];
        }

        return app(\App\Services\ClinicWorkingHoursService::class)->normalizeSchedule($payload);
    }
}

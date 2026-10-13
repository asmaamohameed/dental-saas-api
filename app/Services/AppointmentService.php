<?php

namespace App\Services;

use App\Models\TreatmentTemplate;

class AppointmentService
{
    /**
     * Apply service-linked defaults (fee and duration snapshots) before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyServiceDefaults(array $data): array
    {
        if (! array_key_exists('service_id', $data) || $data['service_id'] === null) {
            return $data;
        }

        /** @var TreatmentTemplate $template */
        $template = TreatmentTemplate::query()->findOrFail($data['service_id']);

        if (! array_key_exists('fee', $data) || $data['fee'] === null) {
            $data['fee'] = $template->default_price;
        }

        if (! array_key_exists('duration_minutes', $data) || $data['duration_minutes'] === null) {
            $data['duration_minutes'] = $template->estimated_duration_minutes;
        }

        return $data;
    }
}

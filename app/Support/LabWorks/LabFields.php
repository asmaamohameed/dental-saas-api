<?php

namespace App\Support\LabWorks;

class LabFields
{
    /**
     * @return array<string, string>
     */
    public static function work(): array
    {
        return [
            'patient_id' => __('lab.fields.patient'),
            'lab_id' => __('lab.fields.lab'),
            'description' => __('lab.fields.work'),
            'notes' => __('lab.fields.notes'),
            'sent_on' => __('lab.fields.sent_on'),
            'expected_on' => __('lab.fields.expected_on'),
            'received_on' => __('lab.fields.received_on'),
            'status' => __('lab.fields.status'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lab(): array
    {
        return [
            'name' => __('lab.fields.lab_name'),
            'phone' => __('lab.fields.phone'),
            'contact_person' => __('lab.fields.contact_person'),
            'address' => __('lab.fields.address'),
            'default_turnaround_days' => __('lab.fields.turnaround'),
            'notes' => __('lab.fields.notes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function workName(): array
    {
        return [
            'name' => __('lab.fields.work_name'),
        ];
    }
}

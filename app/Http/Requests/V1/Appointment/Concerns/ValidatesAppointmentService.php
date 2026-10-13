<?php

namespace App\Http\Requests\V1\Appointment\Concerns;

use App\Models\TreatmentTemplate;
use App\Support\Validation\MoneyAmountRules;
use Illuminate\Validation\Rule;

trait ValidatesAppointmentService
{
    /**
     * @return array<string, mixed>
     */
    protected function serviceFieldRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'nullable';

        return [
            'service_id' => [
                $required,
                'uuid',
                Rule::exists('treatment_templates', 'id')->where(function ($query) {
                    $query->where('tenant_id', $this->user()->tenant_id)
                        ->where('is_active', true);
                }),
            ],
            'fee' => MoneyAmountRules::wholeUnit(),
        ];
    }

    protected function mergeServiceDurationDefault(): void
    {
        if (! $this->filled('service_id') || $this->filled('duration_minutes')) {
            return;
        }

        $template = TreatmentTemplate::query()->find($this->input('service_id'));
        if ($template !== null) {
            $this->merge(['duration_minutes' => $template->estimated_duration_minutes]);
        }
    }
}

<?php

namespace App\Services;

use App\Enums\TreatmentVisitType;
use App\Models\Component;
use App\Models\TreatmentTemplate;
use App\Models\TreatmentTemplateStep;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreatmentTemplateService
{
    public function create(array $data): TreatmentTemplate
    {
        return DB::transaction(function () use ($data) {
            $steps = $data['steps'] ?? [];
            unset($data['steps']);

            $data['version'] = 1;
            $data['is_current'] = true;
            $data['visit_type'] = $this->visitTypeFromSteps($steps, $data['visit_type'] ?? null);

            $template = TreatmentTemplate::create($data);
            $this->writeSteps($template, $steps);

            return $template->load('steps.components.component');
        });
    }

    public function update(TreatmentTemplate $template, array $data): TreatmentTemplate
    {
        if (! $template->is_current) {
            throw ValidationException::withMessages([
                'template' => 'Only the current template version can be edited. Editing creates a new version.',
            ]);
        }

        return DB::transaction(function () use ($template, $data) {
            $steps = $data['steps'] ?? $template->steps()->with('components')->get()->map(function (TreatmentTemplateStep $step) {
                return [
                    'name_ar' => $step->name_ar,
                    'name_en' => $step->name_en,
                    'description' => $step->description,
                    'step_order' => $step->step_order,
                    'is_required' => $step->is_required,
                    'is_repeatable' => $step->is_repeatable,
                    'default_duration_minutes' => $step->default_duration_minutes,
                    'components' => $step->components->map(fn ($component) => [
                        'component_id' => $component->component_id,
                        'quantity' => (float) $component->quantity,
                        'free_quantity' => (float) $component->free_quantity,
                        'unit_price' => (float) $component->unit_price,
                    ])->all(),
                ];
            })->all();

            unset($data['steps']);

            $attributes = array_merge(
                $template->only([
                    'name_ar',
                    'name_en',
                    'category',
                    'description',
                    'default_price',
                    'estimated_duration_minutes',
                    'visit_type',
                    'is_active',
                ]),
                $data,
                [
                    'version' => $template->version + 1,
                    'is_current' => true,
                    'root_template_id' => $template->root_template_id ?: $template->id,
                    'previous_version_id' => $template->id,
                    'visit_type' => $this->visitTypeFromSteps($steps, $data['visit_type'] ?? $template->visit_type?->value),
                ]
            );

            $template->update(['is_current' => false]);

            $next = TreatmentTemplate::create($attributes);
            $this->writeSteps($next, $steps);

            return $next->load('steps.components.component');
        });
    }

    public function destroy(TreatmentTemplate $template): void
    {
        if (! $template->is_current) {
            throw ValidationException::withMessages([
                'template' => 'Only the current template version can be deleted.',
            ]);
        }

        if ($template->familyHasPatientTreatments()) {
            $template->update(['is_active' => false]);

            return;
        }

        DB::transaction(function () use ($template) {
            $rootId = $template->root_template_id ?: $template->id;
            TreatmentTemplate::query()
                ->where('root_template_id', $rootId)
                ->orderByDesc('version')
                ->get()
                ->each->delete();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function writeSteps(TreatmentTemplate $template, array $steps): void
    {
        foreach ($steps as $index => $stepData) {
            $components = $stepData['components'] ?? [];
            unset($stepData['components']);

            $step = $template->steps()->create([
                'name_ar' => $stepData['name_ar'],
                'name_en' => $stepData['name_en'] ?? null,
                'description' => $stepData['description'] ?? null,
                'step_order' => $stepData['step_order'] ?? ($index + 1),
                'is_required' => (bool) ($stepData['is_required'] ?? true),
                'is_repeatable' => (bool) ($stepData['is_repeatable'] ?? false),
                'default_duration_minutes' => $stepData['default_duration_minutes'] ?? $template->estimated_duration_minutes ?? 30,
            ]);

            foreach ($components as $componentData) {
                if (empty($componentData['component_id'])) {
                    continue;
                }

                $component = Component::query()
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->findOrFail($componentData['component_id']);

                $step->components()->create([
                    'component_id' => $component->id,
                    'quantity' => $componentData['quantity'] ?? 1,
                    'free_quantity' => $componentData['free_quantity'] ?? 0,
                    'unit_price' => $componentData['unit_price'] ?? $component->default_price,
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $steps
     */
    private function visitTypeFromSteps(array $steps, mixed $fallback): string
    {
        if (count($steps) > 1) {
            return TreatmentVisitType::MULTIPLE_VISIT->value;
        }

        if ($fallback instanceof TreatmentVisitType) {
            return $fallback->value;
        }

        return $fallback ?: TreatmentVisitType::SINGLE_VISIT->value;
    }
}

<?php

namespace App\Services;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Models\TreatmentTemplate;
use App\Support\TreatmentTemplateName;

class DefaultTreatmentTemplatesProvisioner
{
    /**
     * @return array<string, TreatmentTemplate>
     */
    public function provision(): array
    {
        $templates = [];

        foreach ($this->definitions() as $key => $definition) {
            $templates[$key] = $this->firstOrCreateByEnglishName($definition);
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function firstOrCreateByEnglishName(array $definition): TreatmentTemplate
    {
        $normalizedEn = TreatmentTemplateName::normalize($definition['name_en']);

        $existing = TreatmentTemplate::query()->get()->first(
            fn (TreatmentTemplate $template) => TreatmentTemplateName::normalize($template->name_en) === $normalizedEn
        );

        if ($existing !== null) {
            return $existing;
        }

        return TreatmentTemplate::query()->create(array_merge($definition, ['is_active' => true]));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            'exam' => [
                'name_ar' => 'فحص',
                'name_en' => 'Exam',
                'category' => TreatmentCategory::VISIT,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'Routine dental examination.',
                'default_price' => 0,
                'estimated_duration_minutes' => 30,
            ],
            'consultation' => [
                'name_ar' => 'استشارة',
                'name_en' => 'Consultation',
                'category' => TreatmentCategory::VISIT,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'Initial or specialist consultation.',
                'default_price' => 0,
                'estimated_duration_minutes' => 30,
            ],
            'follow_up' => [
                'name_ar' => 'متابعة',
                'name_en' => 'Follow-up',
                'category' => TreatmentCategory::VISIT,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'Follow-up visit.',
                'default_price' => 0,
                'estimated_duration_minutes' => 15,
            ],
            'filling' => [
                'name_ar' => 'حشوة',
                'name_en' => 'Filling',
                'category' => TreatmentCategory::MEDICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Direct restoration for a single tooth.',
                'default_price' => 0,
                'estimated_duration_minutes' => 45,
            ],
            'root_canal' => [
                'name_ar' => 'علاج عصب',
                'name_en' => 'Root canal',
                'category' => TreatmentCategory::MEDICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Endodontic treatment per tooth.',
                'default_price' => 0,
                'estimated_duration_minutes' => 90,
            ],
            'crown' => [
                'name_ar' => 'تاج',
                'name_en' => 'Crown',
                'category' => TreatmentCategory::MEDICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Single-tooth crown.',
                'default_price' => 0,
                'estimated_duration_minutes' => 60,
            ],
            'extraction' => [
                'name_ar' => 'خلع',
                'name_en' => 'Extraction',
                'category' => TreatmentCategory::SURGICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Tooth extraction.',
                'default_price' => 0,
                'estimated_duration_minutes' => 30,
            ],
            'cleaning' => [
                'name_ar' => 'تنظيف',
                'name_en' => 'Cleaning',
                'category' => TreatmentCategory::PREVENTIVE,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'Prophylaxis and polishing.',
                'default_price' => 0,
                'estimated_duration_minutes' => 45,
            ],
        ];
    }
}

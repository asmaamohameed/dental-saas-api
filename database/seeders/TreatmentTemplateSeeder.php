<?php

namespace Database\Seeders;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

class TreatmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->command?->warn('TreatmentTemplateSeeder is for non-production environments only.');

            return;
        }

        Tenant::query()->each(function (Tenant $tenant) {
            app(CurrentTenant::class)->set($tenant->id);
            $this->seedTemplates();
        });
    }

    private function seedTemplates(): void
    {
        $templates = [
            [
                'name_ar' => 'حشوة مركبة',
                'name_en' => 'Composite Filling',
                'category' => TreatmentCategory::MEDICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Direct composite restoration for a single tooth.',
                'default_price' => 350,
                'estimated_duration_minutes' => 45,
            ],
            [
                'name_ar' => 'علاج عصب',
                'name_en' => 'Root Canal Treatment',
                'category' => TreatmentCategory::MEDICAL,
                'scope' => TreatmentScope::TOOTH,
                'description' => 'Endodontic treatment per tooth.',
                'default_price' => 1500,
                'estimated_duration_minutes' => 90,
            ],
            [
                'name_ar' => 'تنظيف وتلميع',
                'name_en' => 'Scaling and Polishing',
                'category' => TreatmentCategory::PREVENTIVE,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'Full-mouth prophylaxis.',
                'default_price' => 400,
                'estimated_duration_minutes' => 60,
            ],
            [
                'name_ar' => 'تقويم الربع العلوي',
                'name_en' => 'Upper Quadrant Orthodontics',
                'category' => TreatmentCategory::ORTHODONTIC,
                'scope' => TreatmentScope::QUADRANT,
                'description' => 'Orthodontic care for one quadrant.',
                'default_price' => 8000,
                'estimated_duration_minutes' => 120,
            ],
            [
                'name_ar' => 'طقم أسنان علوي',
                'name_en' => 'Upper Denture',
                'category' => TreatmentCategory::OTHER,
                'scope' => TreatmentScope::ARCH,
                'description' => 'Removable prosthesis for the upper arch.',
                'default_price' => 5000,
                'estimated_duration_minutes' => 120,
            ],
            [
                'name_ar' => 'تبييض',
                'name_en' => 'Teeth Whitening',
                'category' => TreatmentCategory::COSMETIC,
                'scope' => TreatmentScope::FULL_MOUTH,
                'description' => 'In-office whitening session.',
                'default_price' => 1200,
                'estimated_duration_minutes' => 75,
            ],
        ];

        foreach ($templates as $data) {
            TreatmentTemplate::query()->firstOrCreate(
                [
                    'name_en' => $data['name_en'],
                ],
                array_merge($data, ['is_active' => true])
            );
        }
    }
}

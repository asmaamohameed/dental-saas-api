<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Services\TreatmentTemplateService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

class TreatmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(TreatmentTemplateService::class);

        Tenant::query()->each(function (Tenant $tenant) use ($service) {
            app(CurrentTenant::class)->set($tenant->id);
            $this->seedRootCanal($service);
        });
    }

    private function seedRootCanal(TreatmentTemplateService $service): void
    {
        $exists = TreatmentTemplate::query()
            ->where('name_en', 'Root Canal Treatment')
            ->where('is_current', true)
            ->exists();

        if ($exists) {
            return;
        }

        $hypochlorite = Component::query()->firstOrCreate(
            ['name_en' => 'Sodium Hypochlorite'],
            [
                'name_ar' => 'هيبوكلوريت الصوديوم',
                'default_price' => 15,
                'unit' => 'ml',
                'current_quantity' => 200,
                'minimum_threshold' => 20,
                'is_active' => true,
            ]
        );

        $gutta = Component::query()->firstOrCreate(
            ['name_en' => 'Gutta Percha'],
            [
                'name_ar' => 'جاتا بيركا',
                'default_price' => 25,
                'unit' => 'piece',
                'current_quantity' => 80,
                'minimum_threshold' => 10,
                'is_active' => true,
            ]
        );

        $service->create([
            'name_ar' => 'علاج عصب',
            'name_en' => 'Root Canal Treatment',
            'category' => 'medical',
            'description' => 'Multi-session root canal with repeatable irrigation/dressing visits.',
            'default_price' => 1500,
            'estimated_duration_minutes' => 180,
            'is_active' => true,
            'steps' => [
                [
                    'step_order' => 1,
                    'name_ar' => 'فتح الحجرة',
                    'name_en' => 'Access cavity',
                    'description' => 'Create access and locate canals.',
                    'is_required' => true,
                    'is_repeatable' => false,
                    'default_duration_minutes' => 30,
                ],
                [
                    'step_order' => 2,
                    'name_ar' => 'تنظيف وتشكيل',
                    'name_en' => 'Cleaning and shaping',
                    'is_required' => true,
                    'is_repeatable' => false,
                    'default_duration_minutes' => 45,
                ],
                [
                    'step_order' => 3,
                    'name_ar' => 'غسيل وحشو مؤقت',
                    'name_en' => 'Irrigation and dressing',
                    'description' => 'Repeatable until canals are dry and asymptomatic.',
                    'is_required' => true,
                    'is_repeatable' => true,
                    'default_duration_minutes' => 30,
                    'components' => [[
                        'component_id' => $hypochlorite->id,
                        'quantity' => 5,
                        'free_quantity' => 0,
                        'unit_price' => 15,
                    ]],
                ],
                [
                    'step_order' => 4,
                    'name_ar' => 'حشو القنوات',
                    'name_en' => 'Obturation',
                    'is_required' => true,
                    'is_repeatable' => false,
                    'default_duration_minutes' => 40,
                    'components' => [[
                        'component_id' => $gutta->id,
                        'quantity' => 3,
                        'free_quantity' => 0,
                        'unit_price' => 25,
                    ]],
                ],
                [
                    'step_order' => 5,
                    'name_ar' => 'متابعة',
                    'name_en' => 'Follow-up',
                    'is_required' => false,
                    'is_repeatable' => true,
                    'default_duration_minutes' => 20,
                ],
            ],
        ]);
    }
}

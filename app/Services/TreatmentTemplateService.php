<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Models\AuditLog;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\TreatmentTemplateName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreatmentTemplateService
{
    public function create(array $data): TreatmentTemplate
    {
        $data = $this->applyScopeDefaults($data);
        $this->assertUniqueNames(null, $data);

        return TreatmentTemplate::create($data);
    }

    public function update(TreatmentTemplate $template, array $data): TreatmentTemplate
    {
        $data = $this->applyScopeDefaults($data, $template);
        $merged = array_merge($template->only(['name_ar', 'name_en']), $data);
        $this->assertUniqueNames($template, $merged);

        $oldDefaultPrice = (string) $template->default_price;

        $template->update($data);

        $template = $template->fresh();

        if (array_key_exists('default_price', $data) && (string) $template->default_price !== $oldDefaultPrice) {
            $this->logDefaultPriceChange($template, $oldDefaultPrice, (string) $template->default_price);
        }

        return $template;
    }

    public function destroy(TreatmentTemplate $template): void
    {
        if ($template->patientTreatments()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'This template is used by patient treatments and cannot be deleted. Deactivate it instead.',
            ]);
        }

        if ($template->appointments()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'This template is used by appointments and cannot be deleted. Deactivate it instead.',
            ]);
        }

        DB::transaction(function () use ($template) {
            $template->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function applyScopeDefaults(array $data, ?TreatmentTemplate $existing = null): array
    {
        $category = $data['category'] ?? $existing?->category;
        $isVisit = $category instanceof TreatmentCategory
            ? $category->isVisit()
            : $category === TreatmentCategory::VISIT->value;

        if ($isVisit) {
            if (! array_key_exists('scope', $data) || $data['scope'] === null) {
                $data['scope'] = TreatmentScope::FULL_MOUTH;
            }

            return $data;
        }

        if (! array_key_exists('scope', $data) || $data['scope'] === null) {
            $data['scope'] = $existing !== null ? $existing->scope : TreatmentScope::TOOTH;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertUniqueNames(?TreatmentTemplate $except, array $data): void
    {
        $normalizedAr = TreatmentTemplateName::normalize($data['name_ar'] ?? null);
        $normalizedEn = TreatmentTemplateName::normalize($data['name_en'] ?? null);

        $candidates = TreatmentTemplate::query()
            ->when($except, fn ($query) => $query->where('id', '!=', $except->id))
            ->get(['id', 'name_ar', 'name_en']);

        foreach ($candidates as $candidate) {
            if ($normalizedAr !== '' && TreatmentTemplateName::normalize($candidate->name_ar) === $normalizedAr) {
                throw ValidationException::withMessages([
                    'name_ar' => 'A service with this Arabic name already exists.',
                ]);
            }

            if ($normalizedEn !== '' && TreatmentTemplateName::normalize($candidate->name_en) === $normalizedEn) {
                throw ValidationException::withMessages([
                    'name_en' => 'A service with this English name already exists.',
                ]);
            }
        }
    }

    private function logDefaultPriceChange(TreatmentTemplate $template, string $oldPrice, string $newPrice): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        AuditLog::query()->create([
            'tenant_id' => $template->tenant_id,
            'user_id' => $user->id,
            'action' => AuditAction::TREATMENT_TEMPLATE_DEFAULT_PRICE_CHANGED,
            'auditable_type' => $template->getMorphClass(),
            'auditable_id' => $template->id,
            'old_values' => ['default_price' => $oldPrice],
            'new_values' => ['default_price' => $newPrice],
        ]);
    }
}

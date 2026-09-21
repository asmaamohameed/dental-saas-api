<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentVisitType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TreatmentTemplateResource;
use App\Models\Component;
use App\Models\TreatmentTemplate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TreatmentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $query = TreatmentTemplate::query()->with('visits.components.component');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $request->query('search'));
            $query->where(fn ($q) => $q->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));
        }

        return $this->paginatedResponse(
            TreatmentTemplateResource::collection($query->latest()->paginate($perPage)),
            'Treatment templates retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $template = $this->saveTemplate(new TreatmentTemplate, $this->validated($request));

        return $this->successResponse(new TreatmentTemplateResource($template), 'Treatment template created successfully.', 201);
    }

    public function show(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        return $this->successResponse(
            new TreatmentTemplateResource($treatmentTemplate->load('visits.components.component')),
            'Treatment template retrieved successfully.'
        );
    }

    public function update(Request $request, TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $template = $this->saveTemplate($treatmentTemplate, $this->validated($request, true));

        return $this->successResponse(new TreatmentTemplateResource($template), 'Treatment template updated successfully.');
    }

    public function destroy(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $treatmentTemplate->delete();

        return $this->successResponse(null, 'Treatment template deleted successfully.');
    }

    public function toggleActive(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $treatmentTemplate->update(['is_active' => ! $treatmentTemplate->is_active]);

        return $this->successResponse(
            new TreatmentTemplateResource($treatmentTemplate->load('visits.components.component')),
            'Treatment template active status updated successfully.'
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'category' => [$required, Rule::enum(TreatmentCategory::class)],
            'description' => ['nullable', 'string'],
            'default_price' => [$required, 'numeric', 'min:0'],
            'estimated_duration_minutes' => [$required, 'integer', 'min:1'],
            'visit_type' => [$required, Rule::enum(TreatmentVisitType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'visits' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'visits.*.visit_order' => ['required_with:visits', 'integer', 'min:1'],
            'visits.*.name_ar' => ['required_with:visits', 'string', 'max:255'],
            'visits.*.name_en' => ['nullable', 'string', 'max:255'],
            'visits.*.description' => ['nullable', 'string'],
            'visits.*.estimated_duration_minutes' => ['required_with:visits', 'integer', 'min:1'],
            'visits.*.components' => ['sometimes', 'array'],
            'visits.*.components.*.component_id' => [
                'required_with:visits.*.components',
                'uuid',
                Rule::exists('components', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'visits.*.components.*.quantity' => ['required_with:visits.*.components', 'numeric', 'min:0'],
            'visits.*.components.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'visits.*.components.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function saveTemplate(TreatmentTemplate $template, array $data): TreatmentTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $visits = $data['visits'] ?? null;
            unset($data['visits']);

            if ($template->exists) {
                $template->update($data);
            } else {
                $template = TreatmentTemplate::create($data);
            }

            if (is_array($visits)) {
                $template->visits()->each(fn ($visit) => $visit->delete());

                foreach ($visits as $visitData) {
                    $components = $visitData['components'] ?? [];
                    unset($visitData['components']);

                    $visit = $template->visits()->create($visitData);

                    foreach ($components as $componentData) {
                        $component = Component::findOrFail($componentData['component_id']);
                        $visit->components()->create([
                            'component_id' => $component->id,
                            'quantity' => $componentData['quantity'],
                            'free_quantity' => $componentData['free_quantity'] ?? 0,
                            'unit_price' => $componentData['unit_price'] ?? $component->default_price,
                        ]);
                    }
                }
            }

            return $template->load('visits.components.component');
        });
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentVisitType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TreatmentTemplateResource;
use App\Models\TreatmentTemplate;
use App\Services\TreatmentTemplateService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreatmentTemplateController extends Controller
{
    public function __construct(private readonly TreatmentTemplateService $treatmentTemplateService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $query = TreatmentTemplate::query()->with('steps');

        if (! $request->boolean('include_history')) {
            $query->current();
        }

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
        $template = $this->treatmentTemplateService->create($this->validated($request));

        return $this->successResponse(new TreatmentTemplateResource($template), 'Treatment template created successfully.', 201);
    }

    public function show(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        return $this->successResponse(
            new TreatmentTemplateResource($treatmentTemplate->load('steps.components.component')),
            'Treatment template retrieved successfully.'
        );
    }

    public function versions(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $rootId = $treatmentTemplate->root_template_id ?: $treatmentTemplate->id;
        $versions = TreatmentTemplate::query()
            ->with('steps.components.component')
            ->where('root_template_id', $rootId)
            ->orderByDesc('version')
            ->get();

        return $this->successResponse(
            TreatmentTemplateResource::collection($versions),
            'Treatment template versions retrieved successfully.'
        );
    }

    public function update(Request $request, TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $template = $this->treatmentTemplateService->update($treatmentTemplate, $this->validated($request, true));

        return $this->successResponse(new TreatmentTemplateResource($template), 'Treatment template updated successfully. A new version was created.');
    }

    public function destroy(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $this->treatmentTemplateService->destroy($treatmentTemplate);

        return $this->successResponse(null, 'Treatment template deleted successfully.');
    }

    public function toggleActive(TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $treatmentTemplate->update(['is_active' => ! $treatmentTemplate->is_active]);

        return $this->successResponse(
            new TreatmentTemplateResource($treatmentTemplate->load('steps.components.component')),
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
            'visit_type' => ['sometimes', Rule::enum(TreatmentVisitType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'steps.*.step_order' => ['required_with:steps', 'integer', 'min:1'],
            'steps.*.name_ar' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.name_en' => ['nullable', 'string', 'max:255'],
            'steps.*.description' => ['nullable', 'string'],
            'steps.*.is_required' => ['sometimes', 'boolean'],
            'steps.*.is_repeatable' => ['sometimes', 'boolean'],
            'steps.*.default_duration_minutes' => ['required_with:steps', 'integer', 'min:1'],
            'steps.*.components' => ['sometimes', 'array'],
            'steps.*.components.*.component_id' => [
                'required_with:steps.*.components',
                'uuid',
                Rule::exists('components', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'steps.*.components.*.quantity' => ['required_with:steps.*.components', 'numeric', 'min:0'],
            'steps.*.components.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'steps.*.components.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}

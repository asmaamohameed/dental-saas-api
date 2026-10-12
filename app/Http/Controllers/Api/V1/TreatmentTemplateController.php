<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TreatmentTemplateResource;
use App\Models\TreatmentTemplate;
use App\Services\TreatmentTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreatmentTemplateController extends Controller
{
    public function __construct(private readonly TreatmentTemplateService $treatmentTemplateService) {}

    public function index(Request $request): JsonResponse
    {
        $query = TreatmentTemplate::query();

        if ($request->has('active')) {
            $query->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        } elseif ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('scope')) {
            $query->where('scope', $request->query('scope'));
        }

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $request->query('search'));
            $query->where(fn ($q) => $q->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));
        }

        if ($request->query('paginate') === 'false') {
            return $this->successResponse(
                TreatmentTemplateResource::collection($query->orderBy('name_en')->get()),
                'Treatment templates retrieved successfully.'
            );
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

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
            new TreatmentTemplateResource($treatmentTemplate),
            'Treatment template retrieved successfully.'
        );
    }

    public function update(Request $request, TreatmentTemplate $treatmentTemplate): JsonResponse
    {
        $template = $this->treatmentTemplateService->update($treatmentTemplate, $this->validated($request, true));

        return $this->successResponse(new TreatmentTemplateResource($template), 'Treatment template updated successfully.');
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
            new TreatmentTemplateResource($treatmentTemplate),
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
            'scope' => [$partial ? 'sometimes' : 'required', Rule::enum(TreatmentScope::class)],
            'description' => ['nullable', 'string'],
            'default_price' => [$required, 'numeric', 'min:0'],
            'estimated_duration_minutes' => [$required, 'integer', 'min:5', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}

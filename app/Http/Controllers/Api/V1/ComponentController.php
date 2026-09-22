<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ComponentResource;
use App\Models\Component;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComponentController extends Controller
{
    public function lowStock(): JsonResponse
    {
        $items = Component::query()->lowStock()->with('inventoryItem')->orderBy('name_en')->get();

        return $this->successResponse(
            ComponentResource::collection($items),
            'Low stock components retrieved successfully.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $query = Component::query()->with('inventoryItem');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $request->query('search'));
            $query->where(fn($q) => $q->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));
        }

        return $this->paginatedResponse(
            ComponentResource::collection($query->latest()->paginate($perPage)),
            'Components retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $component = Component::create($this->validated($request));

        return $this->successResponse(new ComponentResource($component->load('inventoryItem')), 'Component created successfully.', 201);
    }

    public function show(Component $component): JsonResponse
    {
        return $this->successResponse(new ComponentResource($component->load('inventoryItem')), 'Component retrieved successfully.');
    }

    public function update(Request $request, Component $component): JsonResponse
    {
        $component->update($this->validated($request, true));

        return $this->successResponse(new ComponentResource($component->load('inventoryItem')), 'Component updated successfully.');
    }

    public function destroy(Component $component): JsonResponse
    {
        $component->delete();

        return $this->successResponse(null, 'Component deleted successfully.');
    }

    public function toggleActive(Component $component): JsonResponse
    {
        $component->update(['is_active' => !$component->is_active]);

        return $this->successResponse(new ComponentResource($component), 'Component active status updated successfully.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name_ar' => [$prefix, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'default_price' => [$prefix, 'numeric', 'min:0'],
            'unit' => [$prefix, 'string', 'max:50'],
            'inventory_item_id' => [
                'nullable',
                'uuid',
                Rule::exists('inventory_items', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'current_quantity' => ['sometimes', 'numeric', 'min:0'],
            'minimum_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}

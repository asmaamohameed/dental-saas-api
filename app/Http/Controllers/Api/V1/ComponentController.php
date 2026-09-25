<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ComponentResource;
use App\Http\Resources\V1\ComponentStockMovementResource;
use App\Models\Component;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            $query->where(fn ($q) => $q->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));
        }

        return $this->paginatedResponse(
            ComponentResource::collection($query->latest()->paginate($perPage)),
            'Components retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $component = DB::transaction(function () use ($request) {
            $component = Component::create($this->validated($request));
            $quantity = (int) $component->current_quantity;

            if ($quantity > 0) {
                $component->movements()->create([
                    'type' => 'in',
                    'quantity' => $quantity,
                    'performed_by' => $request->user()->id,
                ]);
            }

            return $component;
        });

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

    public function adjustStock(Request $request, Component $component): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $movement = DB::transaction(function () use ($component, $data, $request) {
            /** @var Component $locked */
            $locked = Component::query()->lockForUpdate()->findOrFail($component->id);
            $next = $data['type'] === 'out'
                ? (int) $locked->current_quantity - (int) $data['quantity']
                : (int) $locked->current_quantity + (int) $data['quantity'];

            if ($next < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient stock.',
                ]);
            }

            $locked->update(['current_quantity' => $next]);

            return $locked->movements()->create([
                'type' => $data['type'],
                'quantity' => (int) $data['quantity'],
                'performed_by' => $request->user()->id,
            ]);
        });

        return $this->successResponse([
            'movement' => new ComponentStockMovementResource($movement->load('performer')),
            'component' => new ComponentResource($component->fresh()->load('inventoryItem')),
        ], 'Component stock updated successfully.');
    }

    public function movements(Component $component): JsonResponse
    {
        $movements = $component->movements()->with('performer')->latest('created_at')->limit(50)->get();

        return $this->successResponse(
            ComponentStockMovementResource::collection($movements),
            'Component stock history retrieved successfully.'
        );
    }

    public function toggleActive(Component $component): JsonResponse
    {
        $component->update(['is_active' => ! $component->is_active]);

        return $this->successResponse(new ComponentResource($component), 'Component active status updated successfully.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name_ar' => [$prefix, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'default_price' => [$prefix, 'integer', 'min:0'],
            'unit' => [$prefix, 'string', 'max:50'],
            'inventory_item_id' => [
                'nullable',
                'uuid',
                Rule::exists('inventory_items', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'current_quantity' => ['sometimes', 'integer', 'min:0'],
            'minimum_threshold' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}

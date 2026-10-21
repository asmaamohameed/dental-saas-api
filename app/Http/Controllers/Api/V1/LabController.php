<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Lab\StoreLabRequest;
use App\Http\Requests\V1\Lab\UpdateLabRequest;
use App\Http\Resources\V1\LabResource;
use App\Models\Lab;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lab::class);

        $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = (string) $request->input('status', 'active');

        if ($status !== 'active') {
            $this->authorize('manage', Lab::class);
        }

        $query = Lab::query();

        match ($status) {
            'inactive' => $query->where('is_active', false),
            'all' => null,
            default => $query->where('is_active', true),
        };

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $labs = $query->orderBy('name')->paginate($perPage);

        return $this->paginatedResponse(
            LabResource::collection($labs),
            'Labs retrieved successfully.'
        );
    }

    public function store(StoreLabRequest $request): JsonResponse
    {
        $attributes = $this->attributes($request->validated());
        $attributes['is_active'] = true;
        $lab = Lab::query()->create($attributes);

        return $this->successResponse(
            new LabResource($lab),
            'Lab created successfully.',
            201
        );
    }

    public function show(Lab $lab): JsonResponse
    {
        $this->authorize('view', $lab);

        return $this->successResponse(new LabResource($lab), 'Lab retrieved successfully.');
    }

    public function update(UpdateLabRequest $request, Lab $lab): JsonResponse
    {
        $lab->update($this->attributes($request->validated()));

        return $this->successResponse(new LabResource($lab), 'Lab updated successfully.');
    }

    public function deactivate(Lab $lab): JsonResponse
    {
        $this->authorize('update', $lab);

        $lab->update(['is_active' => false]);

        return $this->successResponse(new LabResource($lab), 'Lab deactivated successfully.');
    }

    public function reactivate(Lab $lab): JsonResponse
    {
        $this->authorize('update', $lab);

        $lab->update(['is_active' => true]);

        return $this->successResponse(new LabResource($lab), 'Lab reactivated successfully.');
    }

    public function destroy(Lab $lab): JsonResponse
    {
        $this->authorize('delete', $lab);

        if ($lab->labWorks()->exists()) {
            return $this->errorResponse(
                __('lab.lab_has_work'),
                422
            );
        }

        $lab->delete();

        return $this->successResponse(null, 'Lab deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        foreach (['contact_person', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        if (array_key_exists('default_turnaround_days', $data) && $data['default_turnaround_days'] === '') {
            $data['default_turnaround_days'] = null;
        }

        return $data;
    }
}

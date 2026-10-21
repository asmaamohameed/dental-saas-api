<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LabWorkType\StoreLabWorkTypeRequest;
use App\Http\Resources\V1\LabWorkTypeResource;
use App\Models\LabWorkType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class LabWorkTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LabWorkType::class);

        $types = LabWorkType::query()->orderBy('name')->get();

        return $this->successResponse(
            LabWorkTypeResource::collection($types),
            'Lab work names retrieved successfully.'
        );
    }

    public function store(StoreLabWorkTypeRequest $request): JsonResponse
    {
        try {
            $type = LabWorkType::query()->create([
                'name' => $request->validated('name'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->errorResponse(__('lab.work_already_listed'), 422, [
                'name' => [__('lab.work_already_listed')],
            ]);
        }

        return $this->successResponse(
            new LabWorkTypeResource($type),
            'Lab work name saved successfully.',
            201
        );
    }

    public function destroy(LabWorkType $labWorkType): JsonResponse
    {
        $this->authorize('delete', $labWorkType);

        $labWorkType->delete();

        return $this->successResponse(null, 'Lab work name removed successfully.');
    }
}

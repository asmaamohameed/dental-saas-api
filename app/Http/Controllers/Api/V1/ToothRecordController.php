<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ToothRecordResource;
use App\Models\Patient;
use App\Models\ToothRecord;
use App\Services\OdontogramService;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ToothRecordController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly OdontogramService $odontogramService) {}

    public function index(Request $request, Patient $patient)
    {
        $this->authorize('viewAny', [ToothRecord::class, $patient]);

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $records = $patient->toothRecords()->latest()->paginate($perPage);

        return $this->paginatedResponse(ToothRecordResource::collection($records));
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', [ToothRecord::class, $patient]);

        return $this->errorResponse(
            'Manual tooth condition records are no longer supported. Tooth state is derived from patient treatments.',
            410,
            ['tooth_records' => ['This endpoint has been retired. Use patient treatments to record clinical work on teeth.']]
        );
    }

    public function odontogram(Patient $patient): JsonResponse
    {
        $this->authorize('viewAny', [ToothRecord::class, $patient]);

        return $this->successResponse(
            $this->odontogramService->buildForPatient($patient),
            'Odontogram retrieved successfully.'
        );
    }
}

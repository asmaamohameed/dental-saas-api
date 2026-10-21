<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LabWorkListTab;
use App\Enums\LabWorkStatus;
use App\Enums\LabWorkTimingFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LabWork\IndexLabWorkRequest;
use App\Http\Requests\V1\LabWork\StoreLabWorkRequest;
use App\Http\Requests\V1\LabWork\UpdateLabWorkRequest;
use App\Http\Requests\V1\LabWork\UpdateLabWorkStatusRequest;
use App\Http\Resources\V1\LabWorkResource;
use App\Models\LabWork;
use App\Models\Patient;
use App\Services\LabWorkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class LabWorkController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly LabWorkService $labWorks) {}

    public function index(IndexLabWorkRequest $request): JsonResponse
    {
        $works = $this->labWorks->list($this->filters($request), $this->perPage($request));

        return $this->paginatedResponse(
            LabWorkResource::collection($works),
            'Lab works retrieved successfully.'
        );
    }

    public function forPatient(IndexLabWorkRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $filters = $this->filters($request);
        $filters['patient_id'] = $patient->id;

        $works = $this->labWorks->list($filters, $this->perPage($request));

        return $this->paginatedResponse(
            LabWorkResource::collection($works),
            'Lab works retrieved successfully.'
        );
    }

    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', LabWork::class);

        return $this->successResponse(
            $this->labWorks->summary(),
            'Lab work summary retrieved successfully.'
        );
    }

    public function store(StoreLabWorkRequest $request): JsonResponse
    {
        $work = $this->labWorks->create($request->validated());

        return $this->successResponse(
            new LabWorkResource($work),
            'Lab work created successfully.',
            201
        );
    }

    public function show(LabWork $labWork): JsonResponse
    {
        $this->authorize('view', $labWork);

        $labWork->load([
            'patient:id,full_name',
            'lab:id,name,is_active',
        ]);

        return $this->successResponse(
            new LabWorkResource($labWork),
            'Lab work retrieved successfully.'
        );
    }

    public function update(UpdateLabWorkRequest $request, LabWork $labWork): JsonResponse
    {
        $work = $this->labWorks->update($labWork, $request->validated());

        return $this->successResponse(
            new LabWorkResource($work),
            'Lab work updated successfully.'
        );
    }

    public function updateStatus(UpdateLabWorkStatusRequest $request, LabWork $labWork): JsonResponse
    {
        $status = $request->enum('status', LabWorkStatus::class);
        $this->authorize(
            $status === LabWorkStatus::CANCELLED ? 'cancel' : 'markReceived',
            $labWork
        );

        $work = $this->labWorks->transition(
            $labWork,
            $status,
            $request->validated('received_on')
        );

        return $this->successResponse(
            new LabWorkResource($work),
            'Lab work status updated successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(IndexLabWorkRequest $request): array
    {
        return [
            'tab' => $request->enum('tab', LabWorkListTab::class) ?? LabWorkListTab::AT_LAB,
            'timing' => $request->enum('timing', LabWorkTimingFilter::class),
            'lab_id' => $request->validated('lab_id'),
            'patient_id' => $request->validated('patient_id'),
            'search' => $request->validated('search'),
            'date_from' => $request->validated('date_from'),
            'date_to' => $request->validated('date_to'),
        ];
    }

    private function perPage(IndexLabWorkRequest $request): int
    {
        return max(1, min((int) $request->input('per_page', 15), 100));
    }
}

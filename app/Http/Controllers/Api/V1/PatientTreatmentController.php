<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PatientTreatmentStatus;
use App\Enums\PatientTreatmentVisitStatus;
use App\Enums\TreatmentPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PatientTreatmentResource;
use App\Http\Resources\V1\PatientTreatmentVisitResource;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentVisit;
use App\Services\PatientTreatmentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientTreatmentController extends Controller
{
    public function __construct(private readonly PatientTreatmentService $patientTreatmentService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        return $this->paginatedResponse(
            PatientTreatmentResource::collection($this->patientTreatmentService->list($request->only(['patient_id', 'status', 'doctor_id']), $perPage)),
            'Patient treatments retrieved successfully.'
        );
    }

    public function forPatient(Request $request, Patient $patient): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        return $this->paginatedResponse(
            PatientTreatmentResource::collection($this->patientTreatmentService->list(['patient_id' => $patient->id, ...$request->only(['status', 'doctor_id'])], $perPage)),
            'Patient treatments retrieved successfully.'
        );
    }

    public function next(Patient $patient): JsonResponse
    {
        $treatment = $this->patientTreatmentService->nextForPatient($patient->id);

        return $this->successResponse(
            $treatment ? new PatientTreatmentResource($treatment) : null,
            'Next treatment retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $treatment = $this->patientTreatmentService->createFromTemplate($this->validated($request), $request->user()->id);

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Patient treatment created successfully.', 201);
    }

    public function show(PatientTreatment $patientTreatment): JsonResponse
    {
        return $this->successResponse(
            new PatientTreatmentResource($patientTreatment->load(['patient', 'template', 'doctor', 'visits.components', 'visits.appointments'])),
            'Patient treatment retrieved successfully.'
        );
    }

    public function update(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $treatment = $this->patientTreatmentService->update($patientTreatment, $this->validated($request, true));

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Patient treatment updated successfully.');
    }

    public function updateVisit(Request $request, PatientTreatmentVisit $visit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(PatientTreatmentVisitStatus::class)],
            'completed_at' => ['nullable', 'date'],
        ]);

        if ($data['status'] === PatientTreatmentVisitStatus::COMPLETED->value && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        if ($data['status'] !== PatientTreatmentVisitStatus::COMPLETED->value) {
            $data['completed_at'] = null;
        }

        $visit->update($data);

        return $this->successResponse(
            new PatientTreatmentVisitResource($visit->load(['components', 'appointments'])),
            'Treatment visit updated successfully.'
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'patient_id' => [
                $required,
                'uuid',
                Rule::exists('patients', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'treatment_template_id' => [
                $required,
                'uuid',
                Rule::exists('treatment_templates', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'doctor_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'tooth_number' => ['nullable', 'string', 'max:20'],
            'diagnosis' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(PatientTreatmentStatus::class)],
            'priority' => ['sometimes', Rule::enum(TreatmentPriority::class)],
            'actual_price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);
    }
}

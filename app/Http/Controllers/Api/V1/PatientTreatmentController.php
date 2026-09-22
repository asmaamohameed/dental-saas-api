<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PatientTreatmentStatus;
use App\Enums\PatientTreatmentVisitStatus;
use App\Enums\ToothCondition;
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

    public function invoiceSummary(PatientTreatment $patientTreatment): JsonResponse
    {
        return $this->successResponse(
            $this->patientTreatmentService->getInvoiceSummary($patientTreatment),
            'Treatment invoice summary retrieved successfully.'
        );
    }

    public function update(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $treatment = $this->patientTreatmentService->update($patientTreatment, $this->validated($request, true), $request->user()->id);

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Patient treatment updated successfully.');
    }

    public function destroy(PatientTreatment $patientTreatment): JsonResponse
    {
        $this->patientTreatmentService->delete($patientTreatment);

        return $this->successResponse(null, 'Patient treatment deleted successfully.');
    }

    public function updateVisit(Request $request, PatientTreatmentVisit $visit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(PatientTreatmentVisitStatus::class)],
            'scheduled_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'dentist_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'appointment_id' => [
                'nullable',
                'uuid',
                Rule::exists('appointments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'status_reason' => ['nullable', 'string'],
            'visit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $statusStr = $data['status'] instanceof PatientTreatmentVisitStatus ? $data['status']->value : (string) $data['status'];

        if ($statusStr === PatientTreatmentVisitStatus::COMPLETED->value) {
            $data['completed_date'] = $data['completed_date'] ?? $data['completed_at'] ?? now();
            $data['completed_at'] = $data['completed_date'];
        } else {
            $data['completed_date'] = null;
            $data['completed_at'] = null;
        }

        $result = $this->patientTreatmentService->updateVisit($visit, $data, $request->user()->id);

        return $this->successResponse([
            'visit' => new PatientTreatmentVisitResource($result['visit']),
            'low_stock_warnings' => $result['low_stock_warnings'],
        ], 'Treatment visit updated successfully.');
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
            'tooth_number' => ['nullable', 'string', 'regex:/^[1-4][1-8]$/'],
            'tooth_condition' => ['nullable', Rule::enum(ToothCondition::class)],
            'diagnosis' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(PatientTreatmentStatus::class)],
            'priority' => ['sometimes', Rule::enum(TreatmentPriority::class)],
            'total_visits' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'actual_price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);
    }
}

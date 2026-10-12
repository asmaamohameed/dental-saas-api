<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConsentStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentSessionStatus;
use App\Enums\TreatmentToothStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PatientTreatmentResource;
use App\Http\Resources\V1\TreatmentSessionResource;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\TreatmentSession;
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
            PatientTreatmentResource::collection($this->patientTreatmentService->list(
                $request->only(['patient_id', 'status', 'clinical_status', 'dentist_id', 'treatment_plan_id']),
                $perPage
            )),
            'Patient treatments retrieved successfully.'
        );
    }

    public function forPatient(Request $request, Patient $patient): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        return $this->paginatedResponse(
            PatientTreatmentResource::collection($this->patientTreatmentService->list([
                'patient_id' => $patient->id,
                ...$request->only(['status', 'clinical_status', 'dentist_id', 'treatment_plan_id']),
            ], $perPage)),
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
            new PatientTreatmentResource($patientTreatment->load([
                'patient',
                'template',
                'dentist',
                'plan',
                'teeth',
                'parentTreatment',
                'sessions.dentist',
                'sessions.appointment',
                'invoiceItems',
            ])),
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
        $treatment = $this->patientTreatmentService->update(
            $patientTreatment,
            $this->validated($request, true),
            $request->user()
        );

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Patient treatment updated successfully.');
    }

    public function updateTeeth(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $data = $request->validate([
            'teeth' => ['required', 'array', 'min:1'],
            'teeth.*.tooth_number' => ['required', 'string', 'regex:/^[1-4][1-8]$/'],
            'teeth.*.status' => ['sometimes', Rule::enum(TreatmentToothStatus::class)],
            'teeth.*.price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $treatment = $this->patientTreatmentService->updateTeeth($patientTreatment, $data, $request->user());

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Treatment teeth updated successfully.');
    }

    public function updateStatus(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $data = $request->validate([
            'clinical_status' => ['required', Rule::enum(PatientTreatmentStatus::class)],
            'cancellation_reason' => ['nullable', 'string'],
        ]);

        $treatment = $this->patientTreatmentService->updateClinicalStatus($patientTreatment, $data, $request->user()->id);

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Treatment clinical status updated successfully.');
    }

    public function retreat(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $data = $request->validate([
            'treatment_template_id' => [
                'nullable',
                'uuid',
                Rule::exists('treatment_templates', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'treatment_plan_id' => [
                'nullable',
                'uuid',
                Rule::exists('treatment_plans', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'agreed_price' => ['nullable', 'numeric', 'min:0'],
            'dentist_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'tooth_numbers' => ['sometimes', 'array'],
            'tooth_numbers.*' => ['string', 'regex:/^[1-4][1-8]$/'],
            'scope_detail' => ['nullable', 'string', 'max:20'],
            'diagnosis' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $treatment = $this->patientTreatmentService->retreat($patientTreatment, $data, $request->user()->id);

        return $this->successResponse(new PatientTreatmentResource($treatment), 'Retreatment created successfully.', 201);
    }

    public function destroy(PatientTreatment $patientTreatment): JsonResponse
    {
        $this->patientTreatmentService->delete($patientTreatment);

        return $this->successResponse(null, 'Patient treatment deleted successfully.');
    }

    public function storeSession(Request $request, PatientTreatment $patientTreatment): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => [
                'nullable',
                'uuid',
                Rule::exists('appointments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'dentist_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'session_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(TreatmentSessionStatus::class)],
            'notes' => ['nullable', 'string'],
            'tooth_numbers' => ['sometimes', 'array'],
            'tooth_numbers.*' => ['string', 'regex:/^[1-4][1-8]$/'],
        ]);

        $result = $this->patientTreatmentService->createSession($patientTreatment, $data, $request->user()->id);

        return $this->successResponse([
            'session' => new TreatmentSessionResource($result['session']),
        ], 'Treatment session created successfully.', 201);
    }

    public function updateSession(Request $request, TreatmentSession $treatmentSession): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => [
                'nullable',
                'uuid',
                Rule::exists('appointments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'dentist_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'session_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(TreatmentSessionStatus::class)],
            'notes' => ['nullable', 'string'],
            'tooth_numbers' => ['sometimes', 'array'],
            'tooth_numbers.*' => ['string', 'regex:/^[1-4][1-8]$/'],
        ]);

        $result = $this->patientTreatmentService->updateSession($treatmentSession, $data, $request->user()->id);

        return $this->successResponse([
            'session' => new TreatmentSessionResource($result['session']),
        ], 'Treatment session updated successfully.');
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
            'treatment_plan_id' => [
                'nullable',
                'uuid',
                Rule::exists('treatment_plans', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'dentist_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'doctor_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
            'tooth_numbers' => ['sometimes', 'array'],
            'tooth_numbers.*' => ['string', 'regex:/^[1-4][1-8]$/'],
            'scope_detail' => ['nullable', 'string', 'max:20'],
            'diagnosis' => ['nullable', 'string'],
            'clinical_status' => ['prohibited'],
            'financial_status' => ['prohibited'],
            'status' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'consent_status' => ['sometimes', Rule::enum(ConsentStatus::class)],
            'consent_document_ref' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::enum(TreatmentPriority::class)],
            'agreed_price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}

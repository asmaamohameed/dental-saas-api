<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\TreatmentSessionStatus;
use App\Events\PatientCheckedIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Appointment\StoreAppointmentRequest;
use App\Http\Requests\V1\Appointment\UpdateAppointmentRequest;
use App\Http\Requests\V1\Appointment\UpdateAppointmentStatusRequest;
use App\Http\Resources\V1\AppointmentResource;
use App\Models\Appointment;
use App\Services\PatientTreatmentService;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly PatientTreatmentService $patientTreatmentService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Appointment::class);

        $query = Appointment::query()->with(['patient', 'doctor', 'treatmentSessions.treatment.template']);

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->input('patient_id'));
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->input('doctor_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('scheduled_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('scheduled_at', '<=', $request->input('date_to'));
        }
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $appointments = $query->latest('scheduled_at')->paginate($perPage);

        return $this->paginatedResponse(AppointmentResource::collection($appointments));
    }

    public function store(StoreAppointmentRequest $request)
    {
        $this->authorize('create', Appointment::class);

        $data = $request->validated();
        $treatmentIds = $data['patient_treatment_ids'] ?? [];
        unset($data['patient_treatment_ids']);

        $data['created_by'] = auth()->id();
        $data['status'] = AppointmentStatus::SCHEDULED;
        $data['appointment_type'] ??= $treatmentIds !== []
            ? AppointmentType::TREATMENT_VISIT
            : AppointmentType::CONSULTATION;

        $appointment = Appointment::create($data);

        if ($treatmentIds !== []) {
            $this->patientTreatmentService->attachTreatmentsToAppointment(
                $appointment->id,
                $treatmentIds,
                $appointment->doctor_id,
                $appointment->scheduled_at->toDateTimeString(),
                $request->user()->id
            );
        }

        $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template']);

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment created successfully.',
            201
        );
    }

    public function show(Appointment $appointment)
    {
        $this->authorize('view', $appointment);

        $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template', 'treatmentSessions.steps']);

        return $this->successResponse(new AppointmentResource($appointment));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $data = $request->validated();
        $treatmentIds = $data['patient_treatment_ids'] ?? null;
        unset($data['patient_treatment_ids']);

        $appointment->update($data);

        if (is_array($treatmentIds)) {
            $this->patientTreatmentService->attachTreatmentsToAppointment(
                $appointment->id,
                $treatmentIds,
                $appointment->doctor_id,
                $appointment->scheduled_at->toDateTimeString(),
                $request->user()->id
            );
        }

        $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template']);

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment updated successfully.'
        );
    }

    public function destroy(Appointment $appointment)
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return $this->successResponse(null, 'Appointment deleted successfully.');
    }

    public function updateStatus(UpdateAppointmentStatusRequest $request, Appointment $appointment)
    {
        $data = $request->validated();
        $newStatus = AppointmentStatus::from($data['status']);

        $this->authorize('updateStatus', [$appointment, $newStatus]);

        $previousStatus = $appointment->status;

        $appointment->status = $newStatus;

        if (isset($data['doctor_id']) && $data['doctor_id'] !== $appointment->doctor_id) {
            $appointment->doctor_id = $data['doctor_id'];
        }

        $appointment->save();

        $sessionStatus = match ($appointment->status) {
            AppointmentStatus::CANCELLED => TreatmentSessionStatus::CANCELLED,
            AppointmentStatus::NO_SHOW => TreatmentSessionStatus::NO_SHOW,
            AppointmentStatus::COMPLETED => TreatmentSessionStatus::COMPLETED,
            default => null,
        };

        if ($sessionStatus) {
            $this->patientTreatmentService->syncSessionsFromAppointment(
                $appointment->id,
                $sessionStatus,
                $request->user()->id
            );
        }

        $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template']);

        if (
            $appointment->status === AppointmentStatus::CHECKED_IN
            && $previousStatus !== AppointmentStatus::CHECKED_IN
        ) {
            event(new PatientCheckedIn($appointment));
        }

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment status updated successfully.'
        );
    }
}

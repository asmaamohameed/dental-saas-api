<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\PatientTreatmentVisitStatus;
use App\Events\PatientCheckedIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Appointment\StoreAppointmentRequest;
use App\Http\Requests\V1\Appointment\UpdateAppointmentRequest;
use App\Http\Requests\V1\Appointment\UpdateAppointmentStatusRequest;
use App\Http\Resources\V1\AppointmentResource;
use App\Models\Appointment;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Appointment::class);

        $query = Appointment::query()->with(['patient', 'doctor', 'patientTreatmentVisit.treatment']);

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

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAppointmentRequest $request)
    {
        $this->authorize('create', Appointment::class);

        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['status'] = AppointmentStatus::SCHEDULED;
        $data['appointment_type'] ??= isset($data['patient_treatment_visit_id'])
            ? AppointmentType::TREATMENT_VISIT
            : AppointmentType::CONSULTATION;

        $appointment = Appointment::create($data);
        if ($appointment->patient_treatment_visit_id) {
            $appointment->patientTreatmentVisit()->update(['status' => PatientTreatmentVisitStatus::SCHEDULED]);
        }

        $appointment->load(['patient', 'doctor', 'patientTreatmentVisit.treatment']);

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment created successfully.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Appointment $appointment)
    {
        $this->authorize('view', $appointment);

        $appointment->load(['patient', 'doctor', 'patientTreatmentVisit.treatment']);

        return $this->successResponse(new AppointmentResource($appointment));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $previousVisitId = $appointment->patient_treatment_visit_id;

        $appointment->update($request->validated());

        if ($previousVisitId && $previousVisitId !== $appointment->patient_treatment_visit_id) {
            $appointment->patientTreatmentVisit()->getModel()::whereKey($previousVisitId)
                ->update(['status' => PatientTreatmentVisitStatus::PLANNED]);
        }

        if ($appointment->patient_treatment_visit_id) {
            $appointment->patientTreatmentVisit()->update(['status' => PatientTreatmentVisitStatus::SCHEDULED]);
        }

        $appointment->load(['patient', 'doctor', 'patientTreatmentVisit.treatment']);

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment updated successfully.'
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Appointment $appointment)
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return $this->successResponse(null, 'Appointment deleted successfully.');
    }

    /**
     * Update the status of the specified appointment.
     */
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

        if ($appointment->patient_treatment_visit_id) {
            $visitStatus = match ($appointment->status) {
                AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW => PatientTreatmentVisitStatus::PLANNED,
                AppointmentStatus::COMPLETED => PatientTreatmentVisitStatus::COMPLETED,
                AppointmentStatus::CHECKED_IN => PatientTreatmentVisitStatus::IN_PROGRESS,
                default => PatientTreatmentVisitStatus::SCHEDULED,
            };

            $appointment->patientTreatmentVisit()->update([
                'status' => $visitStatus,
                'completed_at' => $visitStatus === PatientTreatmentVisitStatus::COMPLETED ? now() : null,
            ]);
        }

        $appointment->load(['patient', 'doctor', 'patientTreatmentVisit.treatment']);

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

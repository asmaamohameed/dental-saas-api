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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        if ($request->filled('sort')) {
            $request->validate([
                'sort' => ['required', 'string', Rule::in([
                    'scheduled_at',
                    '-scheduled_at',
                    'checked_in_at',
                    '-checked_in_at',
                ])],
            ]);

            $sort = $request->input('sort');
            if ($sort === 'scheduled_at') {
                $query->orderBy('scheduled_at');
            } elseif ($sort === '-scheduled_at') {
                $query->orderByDesc('scheduled_at');
            } elseif ($sort === 'checked_in_at') {
                $query->orderByRaw('checked_in_at ASC NULLS LAST');
            } else {
                $query->orderByRaw('checked_in_at DESC NULLS LAST');
            }
        } else {
            $query->latest('scheduled_at');
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $appointments = $query->paginate($perPage);

        return $this->paginatedResponse(AppointmentResource::collection($appointments));
    }

    public function store(StoreAppointmentRequest $request)
    {
        $this->authorize('create', Appointment::class);

        $data = $request->validated();
        $treatmentIds = $this->treatmentIdsForType($data, $data['patient_treatment_ids'] ?? []);
        unset($data['patient_treatment_ids']);

        $data['created_by'] = auth()->id();
        $data['status'] = AppointmentStatus::SCHEDULED;

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

        $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template', 'treatmentSessions.treatment.teeth', 'treatmentSessions.steps']);

        return $this->successResponse(new AppointmentResource($appointment));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $data = $request->validated();
        $submittedTreatmentIds = array_key_exists('patient_treatment_ids', $data)
            ? $data['patient_treatment_ids']
            : null;
        unset($data['patient_treatment_ids']);

        $appointment->update($data);

        if (is_array($submittedTreatmentIds)) {
            $treatmentIds = $this->treatmentIdsForType(
                ['appointment_type' => $data['appointment_type'] ?? $appointment->appointment_type],
                $submittedTreatmentIds
            );
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

        if ($appointment->status === $newStatus) {
            $appointment->load(['patient', 'doctor', 'treatmentSessions.treatment.template']);

            return $this->successResponse(
                new AppointmentResource($appointment),
                'Appointment status updated successfully.'
            );
        }

        $this->authorize('updateStatusRole', [$appointment, $newStatus]);

        if (! $appointment->status->canTransitionTo($newStatus)) {
            $from = $appointment->status->value;
            $to = $newStatus->value;

            return $this->errorResponse(
                "Cannot change appointment status from {$from} to {$to}.",
                422,
                ['status' => ["Cannot change appointment status from {$from} to {$to}."]]
            );
        }

        $previousStatus = $appointment->status;
        $transitionAt = now();

        $appointment->status = $newStatus;

        if ($newStatus === AppointmentStatus::CHECKED_IN) {
            $appointment->checked_in_at = $transitionAt;
        } elseif ($newStatus === AppointmentStatus::IN_PROGRESS) {
            $appointment->started_at = $transitionAt;
        } elseif ($newStatus === AppointmentStatus::COMPLETED) {
            $appointment->completed_at = $transitionAt;
        }

        $doctorChanged = false;
        if (isset($data['doctor_id']) && $data['doctor_id'] !== $appointment->doctor_id) {
            if ($appointment->booked_doctor_id === null && $appointment->doctor_id !== null) {
                $appointment->booked_doctor_id = $appointment->doctor_id;
            }
            $appointment->doctor_id = $data['doctor_id'];
            $doctorChanged = true;
        }

        DB::transaction(function () use ($appointment, $doctorChanged): void {
            $appointment->save();

            if ($doctorChanged) {
                $this->patientTreatmentService->syncSessionDentistsFromAppointmentDoctor(
                    $appointment->id,
                    (string) $appointment->doctor_id
                );
            }
        });

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
            try {
                event(new PatientCheckedIn($appointment));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment status updated successfully.'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $treatmentIds
     * @return array<int, string>
     */
    private function treatmentIdsForType(array $data, array $treatmentIds): array
    {
        $type = $data['appointment_type'] ?? AppointmentType::CONSULTATION;
        if ($type instanceof AppointmentType) {
            return $type === AppointmentType::TREATMENT_VISIT ? $treatmentIds : [];
        }

        return $type === AppointmentType::TREATMENT_VISIT->value ? $treatmentIds : [];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
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

    public function __construct()
    {
        $this->authorizeResource(Appointment::class, 'appointment');
    }

    public function index(Request $request)
    {
        $query = Appointment::query()->with(['patient', 'doctor']);

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
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['status'] = AppointmentStatus::SCHEDULED;

        $appointment = Appointment::create($data);
        $appointment->load(['patient', 'doctor']);

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
        $appointment->load(['patient', 'doctor']);

        return $this->successResponse(new AppointmentResource($appointment));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $appointment->update($request->validated());
        $appointment->load(['patient', 'doctor']);

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
        $appointment->delete();

        return $this->successResponse(null, 'Appointment deleted successfully.');
    }

    /**
     * Update the status of the specified appointment.
     */
    public function updateStatus(UpdateAppointmentStatusRequest $request, Appointment $appointment)
    {
        $data = $request->validated();

        $appointment->status = $data['status'];

        if (isset($data['doctor_id']) && $data['doctor_id'] !== $appointment->doctor_id) {
            $appointment->doctor_id = $data['doctor_id'];
        }

        $appointment->save();
        $appointment->load(['patient', 'doctor']);

        return $this->successResponse(
            new AppointmentResource($appointment),
            'Appointment status updated successfully.'
        );
    }
}

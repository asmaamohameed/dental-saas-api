<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Patient\StorePatientRequest;
use App\Http\Requests\V1\Patient\UpdatePatientRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Patient;
use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PatientController extends Controller implements HasMiddleware
{
    use ApiResponse, AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(Patient::class, 'patient');
    }

    public static function middleware(): array
    {
        return [
            new Middleware('role:owner', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Patient::query();

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $patients = $query->latest()->paginate($perPage);

        return $this->paginatedResponse(PatientResource::collection($patients));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePatientRequest $request)
    {
        $patient = Patient::create($request->validated());

        return $this->successResponse(
            new PatientResource($patient),
            'Patient created successfully.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Patient $patient)
    {
        return $this->successResponse(new PatientResource($patient));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePatientRequest $request, Patient $patient)
    {
        $patient->update($request->validated());

        return $this->successResponse(
            new PatientResource($patient),
            'Patient updated successfully.'
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Patient $patient)
    {
        if (
            $patient->appointments()->exists()
            || $patient->toothRecords()->exists()
            || $patient->xrayAttachments()->exists()
            || $patient->invoices()->exists()
        ) {
            return $this->errorResponse(
                'Cannot delete patient with existing appointments, tooth records, invoices, or x-ray attachments.',
                422
            );
        }

        $patient->delete();

        return $this->successResponse(null, 'Patient deleted successfully.');
    }
}

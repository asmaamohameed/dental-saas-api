<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ToothRecord\StoreToothRecordRequest;
use App\Http\Resources\V1\ToothRecordResource;
use App\Models\Patient;
use App\Models\ToothRecord;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ToothRecordController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of historical tooth records for the patient.
     */
    public function index(Request $request, Patient $patient)
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $records = $patient->toothRecords()->latest()->paginate($perPage);

        return $this->paginatedResponse(ToothRecordResource::collection($records));
    }

    /**
     * Store a newly created tooth record in storage.
     */
    public function store(StoreToothRecordRequest $request, Patient $patient)
    {
        // Verify appointment belongs to this specific patient
        $patient->appointments()->findOrFail($request->validated('appointment_id'));

        $record = $patient->toothRecords()->create([
            ...$request->validated(),
            'recorded_by' => auth()->id(),
        ]);

        return $this->successResponse(
            new ToothRecordResource($record),
            'Tooth record created successfully.',
            201
        );
    }

    /**
     * Return the latest status per tooth for the Odontogram view.
     */
    public function odontogram(Patient $patient)
    {
        $records = ToothRecord::latestPerTooth($patient->id);

        return $this->successResponse(ToothRecordResource::collection($records));
    }
}

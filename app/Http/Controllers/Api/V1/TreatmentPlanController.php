<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TreatmentPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TreatmentPlanResource;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreatmentPlanController extends Controller
{
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $plans = TreatmentPlan::query()
            ->with(['treatments.template', 'treatments.teeth'])
            ->where('patient_id', $patient->id)
            ->latest()
            ->get();

        return $this->successResponse(
            TreatmentPlanResource::collection($plans),
            'Treatment plans retrieved successfully.'
        );
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(TreatmentPlanStatus::class)],
        ]);

        $plan = $patient->treatmentPlans()->create([
            'title' => $data['title'],
            'status' => $data['status'] ?? TreatmentPlanStatus::ACTIVE,
        ]);

        return $this->successResponse(new TreatmentPlanResource($plan), 'Treatment plan created successfully.', 201);
    }

    public function update(Request $request, TreatmentPlan $treatmentPlan): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(TreatmentPlanStatus::class)],
        ]);

        $treatmentPlan->update($data);

        return $this->successResponse(
            new TreatmentPlanResource($treatmentPlan->load(['treatments.template', 'treatments.teeth'])),
            'Treatment plan updated successfully.'
        );
    }

    public function destroy(TreatmentPlan $treatmentPlan): JsonResponse
    {
        $treatmentPlan->treatments()->update(['treatment_plan_id' => null]);
        $treatmentPlan->delete();

        return $this->successResponse(null, 'Treatment plan deleted successfully.');
    }
}

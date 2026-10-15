<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clinic\UpdateClinicWorkingHoursRequest;
use App\Http\Resources\V1\ClinicWorkingHoursResource;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\ClinicWorkingHoursService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class ClinicWorkingHoursController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClinicWorkingHoursService $clinicWorkingHours,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail(app(CurrentTenant::class)->id());
        $schedule = $this->clinicWorkingHours->resolveForTenant($tenant);

        return $this->successResponse(new ClinicWorkingHoursResource($schedule));
    }

    public function update(UpdateClinicWorkingHoursRequest $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail(app(CurrentTenant::class)->id());
        $this->authorize('updateWorkingHours', $tenant);

        $oldSchedule = $this->clinicWorkingHours->resolveForTenant($tenant);
        $newSchedule = $request->normalizedSchedule();

        $tenant->update(['working_hours' => $newSchedule]);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'action' => AuditAction::CLINIC_WORKING_HOURS_CHANGED,
            'auditable_type' => $tenant->getMorphClass(),
            'auditable_id' => $tenant->id,
            'old_values' => ['working_hours' => $oldSchedule],
            'new_values' => ['working_hours' => $newSchedule],
        ]);

        return $this->successResponse(
            new ClinicWorkingHoursResource($newSchedule),
            'Clinic working hours updated successfully.'
        );
    }
}

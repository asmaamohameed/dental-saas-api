<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Doctor\DoctorAvailabilityRequest;
use App\Http\Requests\V1\Staff\StoreStaffRequest;
use App\Http\Requests\V1\Staff\UpdateStaffRequest;
use App\Http\Resources\V1\DoctorAvailabilityResource;
use App\Http\Resources\V1\DoctorResource;
use App\Http\Resources\V1\StaffResource;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\UserService;
use App\Support\WorkingDay;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UserService $userService,
        private readonly DoctorAvailabilityService $doctorAvailabilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 50);

        $filters = $request->only(['role', 'is_active', 'search']);

        $staff = $this->userService->list($filters, $perPage);

        return $this->paginatedResponse(
            StaffResource::collection($staff),
            'Staff retrieved successfully.'
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $this->userService->create($request->validated());

        return $this->successResponse(
            new StaffResource($user),
            'Staff member created successfully.',
            201
        );
    }

    public function update(UpdateStaffRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $updatedUser = $this->userService->update($user, $request->validated());

        return $this->successResponse(
            new StaffResource($updatedUser),
            'Staff member updated successfully.'
        );
    }

    public function toggleActive(User $user): JsonResponse
    {
        $this->authorize('toggleActive', $user);

        $updatedUser = $this->userService->toggleActive($user);

        return $this->successResponse(
            new StaffResource($updatedUser),
            'Staff active status updated successfully.'
        );
    }

    public function doctorAvailability(DoctorAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasAnyClinicRole([
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ])) {
            abort(403);
        }

        $validated = $request->validated();
        $availability = $this->doctorAvailabilityService->listForCurrentTenant(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        return $this->successResponse(
            DoctorAvailabilityResource::collection($availability),
            'Doctor availability retrieved successfully.'
        );
    }

    public function doctors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'day' => ['sometimes', 'string', Rule::in(WorkingDay::values())],
        ]);

        $doctors = $this->userService->listDoctors($validated['day'] ?? null);

        return $this->successResponse(
            DoctorResource::collection($doctors),
            'Doctors retrieved successfully.'
        );
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexClinicsRequest;
use App\Http\Requests\Admin\IndexMembershipsRequest;
use App\Http\Requests\Admin\StoreClinicMemberRequest;
use App\Http\Requests\Admin\StoreClinicRequest;
use App\Http\Requests\Admin\UpdateClinicRequest;
use App\Http\Resources\Admin\ClinicResource;
use App\Http\Resources\Admin\MembershipResource;
use App\Models\AdminUser;
use App\Models\Clinic;
use App\Services\Admin\AdminClinicService;
use App\Services\Admin\AdminMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function __construct(
        private readonly AdminClinicService $clinics,
        private readonly AdminMembershipService $memberships,
    ) {}

    public function index(IndexClinicsRequest $request): JsonResponse
    {
        $page = $this->clinics->paginate($request->validated());

        return $this->paginatedResponse(ClinicResource::collection($page));
    }

    public function store(StoreClinicRequest $request): JsonResponse
    {
        $clinic = $this->clinics->create($request->validated(), $this->admin($request), $request);

        return $this->successResponse(new ClinicResource($clinic), 'Clinic created.', 201);
    }

    public function addMember(StoreClinicMemberRequest $request, Clinic $clinic): JsonResponse
    {
        $member = $this->memberships->addToClinic($clinic, $request->validated(), $this->admin($request), $request);

        return $this->successResponse(new MembershipResource($member), 'Clinic role saved.', 201);
    }

    public function show(Clinic $clinic): JsonResponse
    {
        return $this->successResponse(new ClinicResource($this->clinics->show($clinic)));
    }

    public function update(UpdateClinicRequest $request, Clinic $clinic): JsonResponse
    {
        $updated = $this->clinics->updateSettings(
            $clinic,
            $request->validated(),
            $this->admin($request),
            $request,
        );

        return $this->successResponse(new ClinicResource($updated), 'Clinic updated successfully.');
    }

    public function suspend(Clinic $clinic, Request $request): JsonResponse
    {
        $result = $this->clinics->suspend($clinic, $this->admin($request), $request);

        return $this->successResponse(
            new ClinicResource($result['clinic']),
            $result['changed'] ? 'Clinic suspended.' : 'Clinic is already suspended.',
        );
    }

    public function activate(Clinic $clinic, Request $request): JsonResponse
    {
        $result = $this->clinics->activate($clinic, $this->admin($request), $request);

        return $this->successResponse(
            new ClinicResource($result['clinic']),
            $result['changed'] ? 'Clinic activated.' : 'Clinic is already active.',
        );
    }

    public function members(IndexMembershipsRequest $request, Clinic $clinic): JsonResponse
    {
        $filters = $request->validated();
        $filters['clinic_id'] = $clinic->id;
        $page = $this->memberships->paginate($filters);

        return $this->paginatedResponse(MembershipResource::collection($page));
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user();

        if (! $admin instanceof AdminUser) {
            abort(403, 'Forbidden.');
        }

        return $admin;
    }
}

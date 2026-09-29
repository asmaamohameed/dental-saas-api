<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexMembershipsRequest;
use App\Http\Requests\Admin\StoreMembershipRequest;
use App\Http\Requests\Admin\UpdateMembershipRequest;
use App\Http\Resources\Admin\MembershipResource;
use App\Models\AdminUser;
use App\Models\ClinicMember;
use App\Services\Admin\AdminMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(private readonly AdminMembershipService $memberships) {}

    public function index(IndexMembershipsRequest $request): JsonResponse
    {
        $page = $this->memberships->paginate($request->validated());

        return $this->paginatedResponse(MembershipResource::collection($page));
    }

    public function store(StoreMembershipRequest $request): JsonResponse
    {
        $member = $this->memberships->create($request->validated(), $this->admin($request), $request);

        return $this->successResponse(new MembershipResource($member), 'Membership created.', 201);
    }

    public function update(UpdateMembershipRequest $request, ClinicMember $membership): JsonResponse
    {
        $member = $this->memberships->changeRole(
            $membership,
            $request->validated('role'),
            $this->admin($request),
            $request,
        );

        return $this->successResponse(new MembershipResource($member), 'Clinic role updated.');
    }

    public function revoke(Request $request, ClinicMember $membership): JsonResponse
    {
        $member = $this->memberships->revoke($membership, $this->admin($request), $request);

        return $this->successResponse(new MembershipResource($member), 'Membership revoked.');
    }

    public function restore(Request $request, ClinicMember $membership): JsonResponse
    {
        $member = $this->memberships->restore($membership, $this->admin($request), $request);

        return $this->successResponse(new MembershipResource($member), 'Membership restored.');
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

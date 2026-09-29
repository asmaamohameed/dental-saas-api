<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUsersRequest;
use App\Http\Resources\Admin\PlatformUserResource;
use App\Models\AdminUser;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformUserController extends Controller
{
    public function __construct(private readonly AdminUserService $users) {}

    public function index(IndexUsersRequest $request): JsonResponse
    {
        $page = $this->users->paginate($request->validated());

        return $this->paginatedResponse(PlatformUserResource::collection($page));
    }

    public function show(string $platformUser): JsonResponse
    {
        return $this->successResponse(new PlatformUserResource($this->users->show($platformUser)));
    }

    public function activate(Request $request, string $platformUser): JsonResponse
    {
        $user = $this->users->activate($platformUser, $this->admin($request), $request);

        return $this->successResponse(new PlatformUserResource($user), 'User activated.');
    }

    public function deactivate(Request $request, string $platformUser): JsonResponse
    {
        $user = $this->users->deactivate($platformUser, $this->admin($request), $request);

        return $this->successResponse(new PlatformUserResource($user), 'User deactivated.');
    }

    public function revokeSessions(Request $request, string $platformUser): JsonResponse
    {
        $user = $this->users->revokeSessions($platformUser, $this->admin($request), $request);

        return $this->successResponse(new PlatformUserResource($user), 'Sessions revoked.');
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

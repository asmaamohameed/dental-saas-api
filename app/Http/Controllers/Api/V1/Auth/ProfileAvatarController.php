<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\StoreProfileAvatarRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\UserAvatarService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileAvatarController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreProfileAvatarRequest $request, UserAvatarService $avatarService): JsonResponse
    {
        $user = $request->user();
        $this->authorize('updateAvatar', $user);

        $updated = $avatarService->store($user, $request->file('avatar'));

        return $this->successResponse([
            'user' => new UserResource($updated->load('tenant')),
        ], 'Profile photo updated successfully.');
    }

    public function destroy(Request $request, UserAvatarService $avatarService): JsonResponse
    {
        $user = $request->user();
        $this->authorize('deleteAvatar', $user);

        $updated = $avatarService->remove($user);

        return $this->successResponse([
            'user' => new UserResource($updated->load('tenant')),
        ], 'Profile photo removed successfully.');
    }
}

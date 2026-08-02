<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $token = $this->authService->loginUser(
            $request->only(['email', 'password']),
            $request->input('device_name', 'api_token')
        );

        $user = User::with('tenant')->where('email', $request->input('email'))->first();

        return $this->successResponse([
            'access_token' => $token,
            'user' => new UserResource($user),
        ], 'Successfully logged in.');
    }
}

<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Models\AdminUser;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $token = $this->authService->loginAdmin(
            $request->only(['email', 'password']),
            $request->input('device_name', 'admin_api_token')
        );

        $admin = AdminUser::where('email', $request->input('email'))->first();

        return $this->successResponse([
            'access_token' => $token,
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
        ], 'Successfully logged in.');
    }
}

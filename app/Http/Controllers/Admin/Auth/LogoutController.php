<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authService->logoutAdmin($request->user());

        return $this->successResponse(null, 'Logged out successfully.');
    }
}

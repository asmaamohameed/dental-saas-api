<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RolePermission\UpdateClinicRolePermissionRequest;
use App\Support\Roles\ClinicPermissionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicRolePermissionController extends Controller
{
    public function __construct(private readonly ClinicPermissionGate $gate) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->gate->editorPayload((string) $request->user()->tenant_id)
        );
    }

    public function update(UpdateClinicRolePermissionRequest $request, string $role): JsonResponse
    {
        $this->gate->save(
            (string) $request->user()->tenant_id,
            $role,
            $request->validated('permissions')
        );

        return $this->successResponse(
            $this->gate->editorPayload((string) $request->user()->tenant_id),
            'Role permissions updated.'
        );
    }
}

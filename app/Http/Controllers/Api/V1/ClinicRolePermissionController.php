<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RolePermission\UpdateClinicRolePermissionRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
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
        $tenantId = (string) $request->user()->tenant_id;
        $before = $this->permissionsFor($tenantId, $role);

        $this->gate->save($tenantId, $role, $request->validated('permissions'));

        $after = $this->permissionsFor($tenantId, $role);
        $tenant = Tenant::query()->findOrFail($tenantId);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'action' => AuditAction::CLINIC_ROLE_PERMISSIONS_CHANGED,
            'auditable_type' => $tenant->getMorphClass(),
            'auditable_id' => $tenant->id,
            'old_values' => [
                'role' => $role,
                'permissions' => $before,
            ],
            'new_values' => [
                'role' => $role,
                'permissions' => $after,
            ],
        ]);

        return $this->successResponse(
            $this->gate->editorPayload($tenantId),
            'Role permissions updated.'
        );
    }

    /**
     * @return array<string, bool>
     */
    private function permissionsFor(string $tenantId, string $role): array
    {
        foreach ($this->gate->editorPayload($tenantId)['roles'] as $entry) {
            if ($entry['name'] === $role) {
                return $entry['permissions'];
            }
        }

        return [];
    }
}

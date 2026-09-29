<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Roles\ClinicPermissionCatalog;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->successResponse($this->payload());
    }

    public function show(string $role): JsonResponse
    {
        $matrix = ClinicPermissionCatalog::matrix();

        if (! isset($matrix[$role])) {
            abort(404, 'Resource not found.');
        }

        $record = Role::query()->where('name', $role)->first();

        return $this->successResponse([
            'editable' => false,
            'role' => [
                'id' => $record?->id,
                'name' => $role,
                'label' => $record?->label,
                'permissions' => $matrix[$role],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $matrix = ClinicPermissionCatalog::matrix();
        $labels = ClinicPermissionCatalog::labels();
        $records = Role::query()->orderBy('id')->get()->keyBy('name');

        $roles = [];

        foreach ($matrix as $name => $permissions) {
            $record = $records->get($name);
            $roles[] = [
                'id' => $record?->id,
                'name' => $name,
                'label' => $record?->label,
                'permissions' => $permissions,
            ];
        }

        $catalog = [];

        foreach ($labels as $key => $label) {
            $granted = [];

            foreach ($matrix as $role => $permissions) {
                $granted[$role] = (bool) ($permissions[$key] ?? false);
            }

            $catalog[] = [
                'key' => $key,
                'label' => $label,
                'roles' => $granted,
            ];
        }

        return [
            'editable' => false,
            'roles' => $roles,
            'catalog' => $catalog,
        ];
    }
}

<?php

namespace App\Support\Roles;

use App\Models\ClinicRolePermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One lookup per clinic per request. The user's role picks the row.
 * A saved value is used only when the owner changed it from the baseline.
 */
class ClinicPermissionGate
{
    /** @var list<string> */
    public const EDITABLE_ROLES = ['doctor', 'assistant', 'receptionist'];

    /** @var array<string, array<string, array<string, bool>>> */
    private array $stored = [];

    /** @var array<string, true> */
    private array $loaded = [];

    public function flush(): void
    {
        $this->stored = [];
        $this->loaded = [];
    }

    /**
     * True, false, or null when this clinic has not changed the baseline.
     */
    public function override(User $user, string $permission): ?bool
    {
        $tenantId = $user->tenant_id;

        if (! is_string($tenantId) || $tenantId === '') {
            return null;
        }

        $baseline = ClinicPermissionCatalog::baseline();
        $rows = $this->rows($tenantId);
        $explicitGrant = false;
        $deferredToRole = false;

        foreach ($user->currentClinicRoleNames() as $role) {
            if ($role === 'owner') {
                return true;
            }

            if (! isset($baseline[$role])) {
                continue;
            }

            $saved = $rows[$role][$permission] ?? null;

            if ($saved === null || (bool) $saved === (bool) $baseline[$role][$permission]) {
                $deferredToRole = true;

                continue;
            }

            if ((bool) $saved) {
                $explicitGrant = true;
            }
        }

        if ($explicitGrant) {
            return true;
        }

        if ($deferredToRole) {
            return null;
        }

        return false;
    }

    public function baselineAllows(User $user, string $permission): bool
    {
        $baseline = ClinicPermissionCatalog::baseline();

        foreach ($user->currentClinicRoleNames() as $role) {
            if (($baseline[$role][$permission] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flags for the signed-in user. Owner keeps every permission.
     *
     * @return array<string, bool>
     */
    public function forUser(User $user): array
    {
        $baseline = ClinicPermissionCatalog::baseline();
        $keys = array_keys(ClinicPermissionCatalog::labels());
        $roles = $user->currentClinicRoleNames();

        if (in_array('owner', $roles, true)) {
            return $baseline['owner'];
        }

        $rows = is_string($user->tenant_id) ? $this->rows($user->tenant_id) : [];
        $granted = array_fill_keys($keys, false);

        foreach ($roles as $role) {
            $defaults = $baseline[$role] ?? null;

            if ($defaults === null) {
                continue;
            }

            $saved = $rows[$role] ?? [];

            foreach ($keys as $key) {
                $value = array_key_exists($key, $saved) ? (bool) $saved[$key] : (bool) $defaults[$key];
                $granted[$key] = $granted[$key] || $value;
            }
        }

        return $granted;
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    public function save(string $tenantId, string $role, array $permissions): void
    {
        $baseline = ClinicPermissionCatalog::baseline()[$role];
        $merged = $baseline;

        foreach ($permissions as $key => $value) {
            if (array_key_exists($key, $baseline)) {
                $merged[$key] = (bool) $value;
            }
        }

        ClinicRolePermission::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'role' => $role],
            ['permissions' => $merged],
        );

        $this->flush();
    }

    /**
     * @return array{roles: list<array{name: string, label: string, permissions: array<string, bool>}>, catalog: list<array{key: string, label: string}>}
     */
    public function editorPayload(string $tenantId): array
    {
        $baseline = ClinicPermissionCatalog::baseline();
        $rows = $this->rows($tenantId);
        $labels = Role::query()->whereIn('name', self::EDITABLE_ROLES)->pluck('label', 'name');
        $roles = [];

        foreach (self::EDITABLE_ROLES as $role) {
            $saved = $rows[$role] ?? [];
            $permissions = $baseline[$role];

            foreach ($permissions as $key => $default) {
                if (array_key_exists($key, $saved)) {
                    $permissions[$key] = (bool) $saved[$key];
                }
            }

            $roles[] = [
                'name' => $role,
                'label' => $labels[$role] ?: ucfirst($role),
                'permissions' => $permissions,
            ];
        }

        $catalog = [];

        foreach (ClinicPermissionCatalog::labels() as $key => $label) {
            $catalog[] = ['key' => $key, 'label' => $label];
        }

        return [
            'roles' => $roles,
            'catalog' => $catalog,
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function rows(string $tenantId): array
    {
        if (! Str::isUuid($tenantId)) {
            return [];
        }

        if (isset($this->loaded[$tenantId])) {
            return $this->stored[$tenantId] ?? [];
        }

        $this->loaded[$tenantId] = true;
        $this->stored[$tenantId] = [];

        $records = ClinicRolePermission::query()
            ->where('tenant_id', $tenantId)
            ->get(['role', 'permissions']);

        foreach ($records as $record) {
            $permissions = [];

            foreach ((array) $record->permissions as $key => $value) {
                $permissions[(string) $key] = (bool) $value;
            }

            $this->stored[$tenantId][$record->role] = $permissions;
        }

        return $this->stored[$tenantId];
    }
}

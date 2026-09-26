<?php

namespace App\Support\Roles;

use App\Enums\UserRole;
use App\Models\ClinicMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClinicMembershipSync
{
    public static function attachLegacyRole(User $user): void
    {
        if (! $user->tenant_id) {
            return;
        }

        $names = self::roleNamesFromUser($user);

        if ($names === []) {
            return;
        }

        $roleIds = Role::query()->pluck('id', 'name')->all();
        $member = ClinicMember::query()->firstOrCreate([
            'clinic_id' => $user->tenant_id,
            'user_id' => $user->id,
        ]);

        if ($member->roles()->exists()) {
            return;
        }

        $attach = [];

        foreach ($names as $name) {
            if (isset($roleIds[$name])) {
                $attach[] = $roleIds[$name];
            }
        }

        if ($attach !== []) {
            $member->roles()->sync($attach);
        }
    }

    public static function replaceLegacyRole(User $user): void
    {
        if (! $user->tenant_id) {
            return;
        }

        $role = $user->role;
        $name = $role instanceof UserRole ? $role->value : (is_string($role) ? $role : null);

        if ($name === null) {
            return;
        }

        $roleIds = Role::query()->pluck('id', 'name')->all();

        if (! isset($roleIds[$name])) {
            return;
        }

        $member = ClinicMember::query()->firstOrCreate([
            'clinic_id' => $user->tenant_id,
            'user_id' => $user->id,
        ]);

        $member->roles()->sync([$roleIds[$name]]);
    }

    public static function backfillMissing(): void
    {
        if (! Schema::hasTable('clinic_members') || ! Schema::hasTable('roles') || ! Schema::hasTable('clinic_member_role')) {
            return;
        }

        $roleIds = DB::table('roles')->pluck('id', 'name')->all();
        $hasAdditionalRoles = Schema::hasColumn('users', 'additional_roles');
        $now = now();

        DB::table('users')
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($roleIds, $hasAdditionalRoles, $now): void {
                foreach ($users as $user) {
                    $exists = DB::table('clinic_members')
                        ->where('clinic_id', $user->tenant_id)
                        ->where('user_id', $user->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $memberId = (string) Str::uuid();

                    DB::table('clinic_members')->insert([
                        'id' => $memberId,
                        'clinic_id' => $user->tenant_id,
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    foreach (self::roleNamesFromRow($user, $hasAdditionalRoles) as $name) {
                        if (! isset($roleIds[$name])) {
                            continue;
                        }

                        DB::table('clinic_member_role')->insert([
                            'clinic_member_id' => $memberId,
                            'role_id' => $roleIds[$name],
                        ]);
                    }
                }
            }, 'id');
    }

    /**
     * @return list<string>
     */
    private static function roleNamesFromUser(User $user): array
    {
        $names = [];
        $role = $user->role;

        if ($role instanceof UserRole) {
            $names[] = $role->value;
        } elseif (is_string($role) && $role !== '') {
            $names[] = $role;
        }

        if (Schema::hasColumn('users', 'additional_roles')) {
            $extra = $user->getAttribute('additional_roles');

            if (is_string($extra)) {
                $decoded = json_decode($extra, true);
                $extra = is_array($decoded) ? $decoded : [];
            }

            if (is_array($extra)) {
                foreach ($extra as $name) {
                    if (is_string($name) && $name !== '') {
                        $names[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function roleNamesFromRow(object $user, bool $hasAdditionalRoles): array
    {
        $names = [];

        if (is_string($user->role ?? null) && $user->role !== '') {
            $names[] = $user->role;
        }

        if ($hasAdditionalRoles && ! empty($user->additional_roles)) {
            $decoded = json_decode((string) $user->additional_roles, true);

            if (is_array($decoded)) {
                foreach ($decoded as $name) {
                    if (is_string($name) && $name !== '') {
                        $names[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }
}

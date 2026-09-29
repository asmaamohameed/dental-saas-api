<?php

namespace App\Support\Admin;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ClinicHealthQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withHealthCounts(Builder $query): Builder
    {
        return $query
            ->select('tenants.*')
            ->withCount([
                'members as members_count',
                'members as active_members_count' => function ($members): void {
                    $members->where('clinic_members.is_active', true)
                        ->whereExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('users')
                                ->whereColumn('users.id', 'clinic_members.user_id')
                                ->where('users.is_active', true);
                        });
                },
                'members as active_owner_count' => function ($members): void {
                    self::countRole($members, 'owner');
                },
                'members as doctor_count' => function ($members): void {
                    self::countRole($members, 'doctor');
                },
                'members as inactive_membership_count' => function ($members): void {
                    $members->where(function ($inner): void {
                        $inner->where('clinic_members.is_active', false)
                            ->orWhereExists(function (QueryBuilder $sub): void {
                                $sub->selectRaw('1')
                                    ->from('users')
                                    ->whereColumn('users.id', 'clinic_members.user_id')
                                    ->where('users.is_active', false);
                            });
                    });
                },
                'members as deactivated_owner_count' => function ($members): void {
                    $members->whereHas('roles', fn ($roles) => $roles->where('name', 'owner'))
                        ->whereExists(function (QueryBuilder $sub): void {
                            $sub->selectRaw('1')
                                ->from('users')
                                ->whereColumn('users.id', 'clinic_members.user_id')
                                ->where('users.is_active', false);
                        });
                },
            ])
            ->selectSub(
                function (QueryBuilder $sub): void {
                    $sub->from('tenants as duplicate_names')
                        ->selectRaw('count(*)')
                        ->whereColumn('duplicate_names.name', 'tenants.name')
                        ->whereColumn('duplicate_names.id', '!=', 'tenants.id');
                },
                'duplicate_name_count'
            );
    }

    private static function countRole(object $members, string $role): void
    {
        $members->where('clinic_members.is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', $role))
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'clinic_members.user_id')
                    ->where('users.is_active', true);
            });
    }

    public static function constrain(Builder $query, string $issue): void
    {
        match ($issue) {
            'no_members' => $query->whereNotExists(fn (QueryBuilder $sub) => self::anyMember($sub)),
            'no_active_owner' => $query->whereNotExists(fn (QueryBuilder $sub) => self::activeRole($sub, UserRole::OWNER->value)),
            'no_doctors' => $query->whereNotExists(fn (QueryBuilder $sub) => self::activeRole($sub, UserRole::DOCTOR->value)),
            'inactive_memberships' => $query->whereExists(fn (QueryBuilder $sub) => self::inactiveMember($sub)),
            'deactivated_owner' => $query->whereExists(fn (QueryBuilder $sub) => self::deactivatedOwner($sub)),
            'duplicate_name' => $query->whereExists(fn (QueryBuilder $sub) => self::duplicateName($sub)),
            'suspended_with_active_members' => $query
                ->where('tenants.status', 'inactive')
                ->whereExists(fn (QueryBuilder $sub) => self::activeMember($sub)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function whereIntegrityIssue(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereNotExists(fn (QueryBuilder $sub) => self::anyMember($sub))
                ->orWhereNotExists(fn (QueryBuilder $sub) => self::activeRole($sub, UserRole::OWNER->value))
                ->orWhereExists(fn (QueryBuilder $sub) => self::deactivatedOwner($sub));
        });
    }

    private static function anyMember(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('clinic_members')
            ->whereColumn('clinic_members.clinic_id', 'tenants.id');
    }

    private static function activeMember(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('clinic_members')
            ->join('users', 'users.id', '=', 'clinic_members.user_id')
            ->whereColumn('clinic_members.clinic_id', 'tenants.id')
            ->where('clinic_members.is_active', true)
            ->where('users.is_active', true);
    }

    private static function activeRole(QueryBuilder $query, string $role): void
    {
        $query->selectRaw('1')
            ->from('clinic_members')
            ->join('clinic_member_role', 'clinic_member_role.clinic_member_id', '=', 'clinic_members.id')
            ->join('roles', 'roles.id', '=', 'clinic_member_role.role_id')
            ->join('users', 'users.id', '=', 'clinic_members.user_id')
            ->whereColumn('clinic_members.clinic_id', 'tenants.id')
            ->where('clinic_members.is_active', true)
            ->where('users.is_active', true)
            ->where('roles.name', $role);
    }

    private static function inactiveMember(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('clinic_members')
            ->leftJoin('users', 'users.id', '=', 'clinic_members.user_id')
            ->whereColumn('clinic_members.clinic_id', 'tenants.id')
            ->where(function (QueryBuilder $inner): void {
                $inner->where('clinic_members.is_active', false)
                    ->orWhere('users.is_active', false);
            });
    }

    private static function deactivatedOwner(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('clinic_members')
            ->join('clinic_member_role', 'clinic_member_role.clinic_member_id', '=', 'clinic_members.id')
            ->join('roles', 'roles.id', '=', 'clinic_member_role.role_id')
            ->join('users', 'users.id', '=', 'clinic_members.user_id')
            ->whereColumn('clinic_members.clinic_id', 'tenants.id')
            ->where('roles.name', UserRole::OWNER->value)
            ->where('users.is_active', false);
    }

    private static function duplicateName(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('tenants as duplicate_names')
            ->whereColumn('duplicate_names.name', 'tenants.name')
            ->whereColumn('duplicate_names.id', '!=', 'tenants.id');
    }
}

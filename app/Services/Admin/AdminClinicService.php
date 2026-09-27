<?php

namespace App\Services\Admin;

use App\Enums\TenantStatus;
use App\Models\AdminUser;
use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\User;
use App\Support\Admin\ClinicHealthQuery;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminClinicService
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  array{name: string, subdomain: string, locale?: string|null}  $data
     */
    public function create(array $data, AdminUser $admin, Request $request): Clinic
    {
        $this->currentTenant->clear();

        $clinic = Clinic::query()->create([
            'name' => $data['name'],
            'subdomain' => $data['subdomain'],
            'locale' => $data['locale'] ?? 'ar',
            'status' => TenantStatus::ACTIVE,
        ]);

        $this->audit->logModel(
            $admin,
            'clinic.created',
            $clinic,
            'clinic',
            (string) $clinic->id,
            null,
            [
                'name' => $clinic->name,
                'subdomain' => $clinic->subdomain,
                'locale' => $clinic->locale,
                'status' => TenantStatus::ACTIVE->value,
            ],
            $request,
        );

        return $this->show($clinic);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $this->currentTenant->clear();

        $query = ClinicHealthQuery::withHealthCounts(Clinic::query());

        if (! empty($filters['search'])) {
            $search = $this->like((string) $filters['search']);
            $query->where(function ($inner) use ($search): void {
                $inner->where('tenants.name', 'like', $search)
                    ->orWhere('tenants.subdomain', 'like', $search);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('tenants.status', $filters['status']);
        }

        if (! empty($filters['issue'])) {
            ClinicHealthQuery::constrain($query, (string) $filters['issue']);
        }

        return $query->latest('tenants.created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function show(Clinic $clinic): Clinic
    {
        $this->currentTenant->clear();

        $loaded = ClinicHealthQuery::withHealthCounts(Clinic::query())
            ->whereKey($clinic->id)
            ->firstOrFail();

        $loaded->setRelation('listedOwners', $this->owners($loaded));
        $loaded->setAttribute('last_activity_at', $this->lastActivity($loaded));

        return $loaded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Clinic $clinic, array $data, AdminUser $admin, Request $request): Clinic
    {
        return DB::transaction(function () use ($clinic, $data, $admin, $request) {
            $this->currentTenant->clear();
            $locked = Clinic::query()->whereKey($clinic->id)->lockForUpdate()->firstOrFail();
            $before = [
                'name' => $locked->name,
                'subdomain' => $locked->subdomain,
                'locale' => $locked->locale,
            ];

            $locked->update($data);

            $this->audit->logModel(
                $admin,
                'clinic.updated',
                $locked,
                'clinic',
                (string) $locked->id,
                $before,
                [
                    'name' => $locked->name,
                    'subdomain' => $locked->subdomain,
                    'locale' => $locked->locale,
                ],
                $request,
            );

            return $this->show($locked);
        });
    }

    /**
     * @return array{clinic: Clinic, changed: bool}
     */
    public function suspend(Clinic $clinic, AdminUser $admin, Request $request): array
    {
        return $this->setStatus($clinic, TenantStatus::INACTIVE, 'clinic.suspended', $admin, $request);
    }

    /**
     * @return array{clinic: Clinic, changed: bool}
     */
    public function activate(Clinic $clinic, AdminUser $admin, Request $request): array
    {
        return $this->setStatus($clinic, TenantStatus::ACTIVE, 'clinic.activated', $admin, $request);
    }

    /**
     * @return array{clinic: Clinic, changed: bool}
     */
    private function setStatus(Clinic $clinic, TenantStatus $status, string $action, AdminUser $admin, Request $request): array
    {
        return DB::transaction(function () use ($clinic, $status, $action, $admin, $request) {
            $this->currentTenant->clear();
            $locked = Clinic::query()->whereKey($clinic->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status->value;

            if ($previous === $status->value) {
                return [
                    'clinic' => $this->show($locked),
                    'changed' => false,
                ];
            }

            $locked->update(['status' => $status]);

            $this->audit->logModel(
                $admin,
                $action,
                $locked,
                'clinic',
                (string) $locked->id,
                ['status' => $previous],
                ['status' => $status->value],
                $request,
            );

            return [
                'clinic' => $this->show($locked),
                'changed' => true,
            ];
        });
    }

    /**
     * @return Collection<int, ClinicMember>
     */
    private function owners(Clinic $clinic)
    {
        return ClinicMember::query()
            ->where('clinic_id', $clinic->id)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner'))
            ->with([
                'roles',
                'user' => fn ($users) => $users->withoutGlobalScope('tenant')->select(['id', 'name', 'email', 'is_active', 'tenant_id']),
            ])
            ->orderBy('created_at')
            ->limit(20)
            ->get();
    }

    private function lastActivity(Clinic $clinic): ?string
    {
        $tokenActivity = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', function ($query) use ($clinic): void {
                $query->select('user_id')
                    ->from('clinic_members')
                    ->where('clinic_id', $clinic->id);
            })
            ->max('last_used_at');

        $appointmentActivity = DB::table('appointments')
            ->where('tenant_id', $clinic->id)
            ->max('updated_at');

        $latest = collect([$tokenActivity, $appointmentActivity])->filter()->sort()->last();

        return $latest ? (string) $latest : null;
    }

    private function like(string $value): string
    {
        $escaped = str_replace(['%', '_'], '', $value);

        return '%'.$escaped.'%';
    }
}

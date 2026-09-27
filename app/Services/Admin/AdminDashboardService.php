<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Clinic;
use App\Support\Admin\ClinicHealthQuery;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->currentTenant->clear();

        $since = now()->subDays(30);
        $counts = DB::table('tenants')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
            ->selectRaw("sum(case when status = 'inactive' then 1 else 0 end) as suspended")
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as new_clinics', [$since])
            ->first();

        $users = DB::table('users')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = true then 1 else 0 end) as active')
            ->first();

        return [
            'clinics' => [
                'total' => (int) ($counts->total ?? 0),
                'active' => (int) ($counts->active ?? 0),
                'suspended' => (int) ($counts->suspended ?? 0),
                'new' => (int) ($counts->new_clinics ?? 0),
                'new_window_days' => 30,
                'no_active_owner' => $this->issueCount('no_active_owner'),
                'no_doctors' => $this->issueCount('no_doctors'),
                'inactive_memberships' => $this->issueCount('inactive_memberships'),
                'integrity_issues' => $this->integrityCount(),
            ],
            'users' => [
                'total' => (int) ($users->total ?? 0),
                'active' => (int) ($users->active ?? 0),
            ],
            'recent_clinics' => Clinic::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'name', 'subdomain', 'status', 'created_at'])
                ->map(fn (Clinic $clinic) => [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'subdomain' => $clinic->subdomain,
                    'status' => $clinic->status->value,
                    'created_at' => $clinic->created_at,
                ])
                ->values(),
            'recent_status_changes' => $this->recentAudits(['clinic.suspended', 'clinic.activated']),
            'recent_activity' => $this->recentAudits(),
        ];
    }

    private function issueCount(string $issue): int
    {
        $query = Clinic::query();
        ClinicHealthQuery::constrain($query, $issue);

        return $query->count();
    }

    private function integrityCount(): int
    {
        $query = Clinic::query();
        ClinicHealthQuery::whereIntegrityIssue($query);

        return $query->count();
    }

    /**
     * @param  list<string>|null  $actions
     * @return list<array{
     *     id: string,
     *     action: string,
     *     target_type: string,
     *     target_id: string|null,
     *     tenant_id: string|null,
     *     admin: array{id: string, name: string, email: string}|null,
     *     created_at: \Illuminate\Support\Carbon|null
     * }>
     */
    private function recentAudits(?array $actions = null): array
    {
        return array_values(AdminAuditLog::query()
            ->with('admin:id,name,email')
            ->when($actions, fn ($query) => $query->whereIn('action', $actions))
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AdminAuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'tenant_id' => $log->tenant_id,
                'admin' => $log->admin ? [
                    'id' => $log->admin->id,
                    'name' => $log->admin->name,
                    'email' => $log->admin->email,
                ] : null,
                'created_at' => $log->created_at,
            ])
            ->all());
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAuditLogsRequest;
use App\Http\Resources\Admin\AdminAuditLogResource;
use App\Models\AdminAuditLog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function index(IndexAuditLogsRequest $request): JsonResponse
    {
        $this->currentTenant->clear();

        $filters = $request->validated();
        $logs = AdminAuditLog::query()
            ->with('admin:id,name,email')
            ->when(! empty($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(! empty($filters['target_type']), fn ($query) => $query->where('target_type', $filters['target_type']))
            ->when(! empty($filters['target_id']), fn ($query) => $query->where('target_id', $filters['target_id']))
            ->when(! empty($filters['tenant_id']), fn ($query) => $query->where('tenant_id', $filters['tenant_id']))
            ->when(! empty($filters['admin_user_id']), fn ($query) => $query->where('admin_user_id', $filters['admin_user_id']))
            ->latest('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse(AdminAuditLogResource::collection($logs));
    }
}

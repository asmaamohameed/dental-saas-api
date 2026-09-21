<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\V1\AuditLogResource;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request)
    {
        $auditLogs = AuditLog::query()
            ->with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('auditable_type'), fn ($q) => $q->where('auditable_type', $request->auditable_type))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->paginatedResponse(AuditLogResource::collection($auditLogs));
    }
}

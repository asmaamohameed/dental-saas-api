<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\Admin\UpdateTenantRequest;
use App\Http\Resources\Admin\TenantResource;
use App\Models\AdminUser;
use App\Models\Clinic;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Admin\AdminClinicService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminClinicService $clinics,
        private readonly AdminAuditLogger $audit,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $tenants = Tenant::latest()->paginate($perPage);

        return $this->paginatedResponse(TenantResource::collection($tenants));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTenantRequest $request)
    {
        $tenant = Tenant::create($request->validated());
        $admin = $request->user();

        if ($admin instanceof AdminUser) {
            $this->audit->logModel(
                $admin,
                'clinic.created',
                $tenant,
                'clinic',
                (string) $tenant->id,
                null,
                [
                    'name' => $tenant->name,
                    'subdomain' => $tenant->subdomain,
                    'locale' => $tenant->locale,
                    'status' => $tenant->status->value,
                ],
                $request,
            );
        }

        return $this->successResponse(
            new TenantResource($tenant),
            'Tenant created successfully.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant)
    {
        return $this->successResponse(new TenantResource($tenant));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $admin = $request->user();
        $data = $request->validated();
        $status = $data['status'] ?? null;
        unset($data['status']);

        if ($data !== [] && $admin instanceof AdminUser) {
            $this->clinics->updateSettings(Clinic::query()->findOrFail($tenant->id), $data, $admin, $request);
        } elseif ($data !== []) {
            $tenant->update($data);
        }

        if ($admin instanceof AdminUser && $status === TenantStatus::INACTIVE->value) {
            $this->clinics->suspend(Clinic::query()->findOrFail($tenant->id), $admin, $request);
        } elseif ($admin instanceof AdminUser && $status === TenantStatus::ACTIVE->value) {
            $this->clinics->activate(Clinic::query()->findOrFail($tenant->id), $admin, $request);
        }

        $tenant->refresh();

        return $this->successResponse(
            new TenantResource($tenant),
            'Tenant updated successfully.'
        );
    }
}

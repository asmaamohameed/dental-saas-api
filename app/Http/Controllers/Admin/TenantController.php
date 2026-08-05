<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\Admin\UpdateTenantRequest;
use App\Http\Resources\Admin\TenantResource;
use App\Models\Tenant;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    use ApiResponse;

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
        $tenant->update($request->validated());

        return $this->successResponse(
            new TenantResource($tenant),
            'Tenant updated successfully.'
        );
    }
}

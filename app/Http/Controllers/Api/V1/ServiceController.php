<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Service\StoreServiceRequest;
use App\Http\Requests\V1\Service\UpdateServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use App\Models\Service;
use App\Services\ServiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(private readonly ServiceService $serviceService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 1), 50);

        $services = $this->serviceService->list($request->all(), $perPage);

        return $this->successResponse(
            ServiceResource::collection($services)->response()->getData(true),
            'Services retrieved successfully.'
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = $this->serviceService->create($request->validated());

        return $this->successResponse(
            new ServiceResource($service),
            'Service created successfully.',
            201
        );
    }

    public function show(Service $service): JsonResponse
    {
        return $this->successResponse(
            new ServiceResource($service),
            'Service retrieved successfully.'
        );
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $updatedService = $this->serviceService->update($service, $request->validated());

        return $this->successResponse(
            new ServiceResource($updatedService),
            'Service updated successfully.'
        );
    }

    public function destroy(Service $service): JsonResponse
    {
        try {
            $this->serviceService->delete($service);

            return $this->successResponse(null, 'Service deleted successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function toggleActive(Service $service): JsonResponse
    {
        try {
            $updatedService = $this->serviceService->toggleActive($service);

            return $this->successResponse(
                new ServiceResource($updatedService),
                'Service active status updated successfully.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}

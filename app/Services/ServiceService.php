<?php

namespace App\Services;

use App\Exceptions\ServiceProtectedException;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ServiceService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Service::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function create(array $data): Service
    {
        return Service::create($data);
    }

    public function update(Service $service, array $data): Service
    {
        if ($service->is_other) {
            // Protect "Other" system service from name/price changes
            unset($data['name_ar'], $data['name_en'], $data['is_other']);
        }

        $service->update($data);

        return $service;
    }

    public function delete(Service $service): bool
    {
        if ($service->is_other) {
            throw new ServiceProtectedException('Cannot delete system reserved "Other" service.');
        }

        if ($service->invoiceItems()->exists()) {
            throw new \InvalidArgumentException('Cannot delete service associated with existing invoice items.');
        }

        return (bool) $service->delete();
    }

    public function toggleActive(Service $service): Service
    {
        if ($service->is_other) {
            throw new \InvalidArgumentException('Cannot deactivate system reserved "Other" service.');
        }

        $service->update(['is_active' => ! $service->is_active]);

        return $service;
    }
}

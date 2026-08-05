<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Http\Resources\Admin\SubscriptionResource;
use App\Models\Subscription;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Subscription::query();

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $subscriptions = $query->latest()->paginate($perPage);

        return $this->paginatedResponse(SubscriptionResource::collection($subscriptions));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSubscriptionRequest $request)
    {
        $subscription = Subscription::create($request->validated());

        return $this->successResponse(
            new SubscriptionResource($subscription),
            'Subscription created successfully.',
            201
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSubscriptionRequest $request, Subscription $subscription)
    {
        $subscription->update($request->validated());

        return $this->successResponse(
            new SubscriptionResource($subscription),
            'Subscription updated successfully.'
        );
    }

    /**
     * Mark the subscription as paid.
     */
    public function markAsPaid(Subscription $subscription)
    {
        $subscription->update([
            'marked_paid_at' => now(),
            'status' => 'active',
        ]);

        return $this->successResponse(
            new SubscriptionResource($subscription),
            'Subscription marked as paid successfully.'
        );
    }
}

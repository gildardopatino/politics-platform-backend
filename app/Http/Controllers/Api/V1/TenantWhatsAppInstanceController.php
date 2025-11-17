<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWhatsAppInstanceRequest;
use App\Http\Requests\UpdateWhatsAppInstanceRequest;
use App\Http\Resources\TenantWhatsAppInstanceResource;
use App\Models\TenantWhatsAppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantWhatsAppInstanceController extends Controller
{
    /**
     * Display a listing of WhatsApp instances for a tenant
     */
    public function index(Request $request, $tenantId = null): JsonResponse
    {
        $query = TenantWhatsAppInstance::query();

        // Filter by tenant if provided (for nested routes)
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Optional filters
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('with_quota')) {
            $query->withAvailableQuota();
        }

        $instances = $query->with(['tenant:id,slug,nombre'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => TenantWhatsAppInstanceResource::collection($instances->items()),
            'meta' => [
                'total' => $instances->total(),
                'current_page' => $instances->currentPage(),
                'last_page' => $instances->lastPage(),
                'per_page' => $instances->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created WhatsApp instance
     */
    public function store(StoreWhatsAppInstanceRequest $request, $tenantId = null): JsonResponse
    {
        $data = $request->validated();

        // Use tenant from route if available (nested route)
        if ($tenantId) {
            $data['tenant_id'] = $tenantId;
        }

        // Initialize counters for new instance
        $data['messages_sent_today'] = 0;
        $data['last_reset_date'] = now();

        $instance = TenantWhatsAppInstance::create($data);

        return response()->json([
            'data' => new TenantWhatsAppInstanceResource($instance->load('tenant')),
            'message' => 'WhatsApp instance created successfully'
        ], 201);
    }

    /**
     * Display the specified WhatsApp instance
     */
    public function show(TenantWhatsAppInstance $instance): JsonResponse
    {
        return response()->json([
            'data' => new TenantWhatsAppInstanceResource($instance->load('tenant'))
        ]);
    }

    /**
     * Update the specified WhatsApp instance
     */
    public function update(UpdateWhatsAppInstanceRequest $request, TenantWhatsAppInstance $instance): JsonResponse
    {
        $instance->update($request->validated());

        return response()->json([
            'data' => new TenantWhatsAppInstanceResource($instance->load('tenant')),
            'message' => 'WhatsApp instance updated successfully'
        ]);
    }

    /**
     * Remove the specified WhatsApp instance
     */
    public function destroy(TenantWhatsAppInstance $instance): JsonResponse
    {
        $instance->delete();

        return response()->json([
            'message' => 'WhatsApp instance deleted successfully'
        ]);
    }

    /**
     * Toggle active status of WhatsApp instance
     */
    public function toggleActive(TenantWhatsAppInstance $instance): JsonResponse
    {
        $instance->update([
            'is_active' => !$instance->is_active
        ]);

        return response()->json([
            'data' => new TenantWhatsAppInstanceResource($instance),
            'message' => $instance->is_active 
                ? 'WhatsApp instance activated' 
                : 'WhatsApp instance deactivated'
        ]);
    }

    /**
     * Reset daily counter manually
     */
    public function resetCounter(TenantWhatsAppInstance $instance): JsonResponse
    {
        $instance->update([
            'messages_sent_today' => 0,
            'last_reset_date' => now()
        ]);

        return response()->json([
            'data' => new TenantWhatsAppInstanceResource($instance),
            'message' => 'Daily counter reset successfully'
        ]);
    }

    /**
     * Get instance statistics
     */
    public function statistics(TenantWhatsAppInstance $instance): JsonResponse
    {
        return response()->json([
            'data' => [
                'instance_id' => $instance->id,
                'phone_number' => $instance->phone_number,
                'is_active' => $instance->is_active,
                'daily_limit' => $instance->daily_message_limit,
                'sent_today' => $instance->messages_sent_today,
                'remaining_today' => $instance->getRemainingQuota(),
                'usage_percentage' => $instance->daily_message_limit > 0 
                    ? round(($instance->messages_sent_today / $instance->daily_message_limit) * 100, 2)
                    : 0,
                'can_send' => $instance->canSendMessage(),
                'last_reset' => $instance->last_reset_date?->format('Y-m-d H:i:s'),
            ]
        ]);
    }
}

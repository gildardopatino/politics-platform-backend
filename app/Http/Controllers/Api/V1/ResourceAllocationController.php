<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResourceAllocation\StoreResourceAllocationRequest;
use App\Http\Requests\Api\V1\ResourceAllocation\UpdateResourceAllocationRequest;
use App\Http\Resources\Api\V1\ResourceAllocationResource;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

class ResourceAllocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $resources = QueryBuilder::for(ResourceAllocation::class)
            ->allowedFilters(['type', 'meeting_id', 'leader_user_id'])
            ->allowedIncludes(['meeting', 'allocatedBy', 'leader'])
            ->allowedSorts(['fecha_asignacion', 'created_at', 'amount'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources->items()),
            'meta' => [
                'total' => $resources->total(),
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceAllocationRequest $request): JsonResponse
    {
        $resource = ResourceAllocation::create([
            'tenant_id' => app('tenant')->id,
            'allocated_by_user_id' => auth()->user()->id,
            ...$request->validated()
        ]);

        return response()->json([
            'data' => new ResourceAllocationResource($resource->load(['meeting', 'allocatedBy', 'leader'])),
            'message' => 'Resource allocation created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ResourceAllocation $resourceAllocation): JsonResponse
    {
        $resourceAllocation->load(['meeting', 'allocatedBy', 'leader']);

        return response()->json([
            'data' => new ResourceAllocationResource($resourceAllocation)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResourceAllocationRequest $request, ResourceAllocation $resourceAllocation): JsonResponse
    {
        $resourceAllocation->update($request->validated());

        return response()->json([
            'data' => new ResourceAllocationResource($resourceAllocation->load(['meeting', 'allocatedBy', 'leader'])),
            'message' => 'Resource allocation updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ResourceAllocation $resourceAllocation): JsonResponse
    {
        $resourceAllocation->delete();

        return response()->json([
            'message' => 'Resource allocation deleted successfully'
        ]);
    }

    /**
     * Get resources by meeting
     */
    public function byMeeting(Meeting $meeting): JsonResponse
    {
        $resources = $meeting->resourceAllocations()
            ->with(['allocatedBy', 'leader'])
            ->get();

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources),
            'total_cash' => $resources->where('type', 'cash')->sum('amount'),
            'total_material' => $resources->where('type', 'material')->sum('amount'),
            'total_service' => $resources->where('type', 'service')->sum('amount'),
        ]);
    }

    /**
     * Get resources by leader
     */
    public function byLeader(User $user): JsonResponse
    {
        $resources = ResourceAllocation::where('leader_user_id', $user->id)
            ->with(['meeting', 'allocatedBy'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources->items()),
            'meta' => [
                'total' => $resources->total(),
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
            ],
            'summary' => [
                'total_cash' => $resources->where('type', 'cash')->sum('amount'),
                'total_material' => $resources->where('type', 'material')->sum('amount'),
                'total_service' => $resources->where('type', 'service')->sum('amount'),
            ]
        ]);
    }
}

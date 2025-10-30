<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Commitment\StoreCommitmentRequest;
use App\Http\Requests\Api\V1\Commitment\UpdateCommitmentRequest;
use App\Http\Resources\Api\V1\CommitmentResource;
use App\Models\Commitment;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

class CommitmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $commitments = QueryBuilder::for(Commitment::class)
            ->allowedFilters(['status', 'meeting_id', 'assigned_user_id', 'priority_id'])
            ->allowedIncludes(['meeting', 'assignedUser', 'priority'])
            ->allowedSorts(['fecha_compromiso', 'created_at', 'status'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => CommitmentResource::collection($commitments->items()),
            'meta' => [
                'total' => $commitments->total(),
                'current_page' => $commitments->currentPage(),
                'last_page' => $commitments->lastPage(),
                'per_page' => $commitments->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCommitmentRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $commitment = Commitment::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$request->validated()
        ]);

        return response()->json([
            'data' => new CommitmentResource($commitment->load(['meeting', 'assignedUser', 'priority'])),
            'message' => 'Commitment created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Commitment $commitment): JsonResponse
    {
        $commitment->load(['meeting', 'assignedUser', 'priority']);

        return response()->json([
            'data' => new CommitmentResource($commitment)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCommitmentRequest $request, Commitment $commitment): JsonResponse
    {
        $commitment->update($request->validated());

        return response()->json([
            'data' => new CommitmentResource($commitment->load(['meeting', 'assignedUser', 'priority'])),
            'message' => 'Commitment updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Commitment $commitment): JsonResponse
    {
        $commitment->delete();

        return response()->json([
            'message' => 'Commitment deleted successfully'
        ]);
    }

    /**
     * Mark commitment as completed
     */
    public function complete(Commitment $commitment): JsonResponse
    {
        $commitment->update([
            'status' => 'completed',
            'fecha_cumplimiento' => now()
        ]);

        return response()->json([
            'data' => new CommitmentResource($commitment),
            'message' => 'Commitment marked as completed'
        ]);
    }

    /**
     * Get commitments by meeting
     */
    public function byMeeting(\App\Models\Meeting $meeting): JsonResponse
    {
        $commitments = QueryBuilder::for(Commitment::class)
            ->where('meeting_id', $meeting->id)
            ->allowedFilters(['status', 'assigned_user_id', 'priority_id'])
            ->allowedIncludes(['assignedUser', 'priority', 'createdBy'])
            ->allowedSorts(['due_date', 'created_at', 'status'])
            ->with(['assignedUser', 'priority'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => CommitmentResource::collection($commitments->items()),
            'meta' => [
                'total' => $commitments->total(),
                'current_page' => $commitments->currentPage(),
                'last_page' => $commitments->lastPage(),
                'per_page' => $commitments->perPage(),
            ]
        ]);
    }

    /**
     * Get overdue commitments
     */
    public function overdue(): JsonResponse
    {
        $commitments = Commitment::overdue()
            ->with(['meeting', 'assignedUser', 'priority'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => CommitmentResource::collection($commitments->items()),
            'meta' => [
                'total' => $commitments->total(),
                'current_page' => $commitments->currentPage(),
                'last_page' => $commitments->lastPage(),
            ]
        ]);
    }
}

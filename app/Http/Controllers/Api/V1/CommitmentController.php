<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Commitment\StoreCommitmentRequest;
use App\Http\Requests\Api\V1\Commitment\UpdateCommitmentRequest;
use App\Http\Resources\Api\V1\CommitmentResource;
use App\Jobs\SendCommitmentReminderJob;
use App\Models\Commitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
            ->allowedIncludes(['meeting', 'assignedUser', 'priority', 'createdBy'])
            ->allowedSorts(['due_date', 'created_at', 'status'])
            ->with(['meeting', 'assignedUser', 'priority'])
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

        // Programar notificaciones de WhatsApp
        $whatsappSent = $this->scheduleCommitmentReminders($commitment);

        return response()->json([
            'data' => new CommitmentResource($commitment->load(['meeting', 'assignedUser', 'priority'])),
            'message' => 'Commitment created successfully',
            'whatsapp_notification_sent' => $whatsappSent,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Commitment $commitment): JsonResponse
    {
        $commitment->load(['meeting', 'assignedUser', 'priority', 'createdBy']);

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

    /**
     * Schedule all WhatsApp reminders for a commitment
     * Sends immediate assignment notification and schedules future reminders
     */
    private function scheduleCommitmentReminders(Commitment $commitment): bool
    {
        // Reload with relationships
        $commitment->load(['assignedUser', 'priority']);

        // Check if assigned user has phone
        if (!$commitment->assignedUser || !$commitment->assignedUser->phone) {
            Log::info("Commitment {$commitment->id}: No phone number for assigned user, skipping WhatsApp notifications");
            return false;
        }

        $now = now();
        $dueDate = \Carbon\Carbon::parse($commitment->due_date);
        $totalDays = $now->diffInDays($dueDate);

        // Dispatch immediate assignment notification
        try {
            SendCommitmentReminderJob::dispatch($commitment, 'assignment');
            Log::info("Commitment {$commitment->id}: Assignment notification dispatched");
        } catch (\Exception $e) {
            Log::error("Commitment {$commitment->id}: Failed to dispatch assignment notification", [
                'error' => $e->getMessage()
            ]);
            return false;
        }

        // Schedule future reminders only if there's enough time (more than 2 days)
        if ($totalDays > 2) {
            // 50% reminder - halfway through the time period
            $fiftyPercentDate = $now->copy()->addDays((int) ($totalDays * 0.5));
            SendCommitmentReminderJob::dispatch($commitment, '50_percent')->delay($fiftyPercentDate);
            Log::info("Commitment {$commitment->id}: 50% reminder scheduled for {$fiftyPercentDate}");

            // 25% reminder - at 75% of time elapsed (25% remaining)
            $twentyFivePercentDate = $now->copy()->addDays((int) ($totalDays * 0.75));
            SendCommitmentReminderJob::dispatch($commitment, '25_percent')->delay($twentyFivePercentDate);
            Log::info("Commitment {$commitment->id}: 25% reminder scheduled for {$twentyFivePercentDate}");
        } else {
            Log::info("Commitment {$commitment->id}: Due in {$totalDays} days, skipping intermediate reminders");
        }

        // Due date reminder - on the due date at 8:00 AM
        $dueDateReminder = $dueDate->copy()->setTime(8, 0);
        
        // Only schedule if due date is in the future
        if ($dueDateReminder->isFuture()) {
            SendCommitmentReminderJob::dispatch($commitment, 'due_date')->delay($dueDateReminder);
            Log::info("Commitment {$commitment->id}: Due date reminder scheduled for {$dueDateReminder}");
        } else {
            Log::info("Commitment {$commitment->id}: Due date is today or past, skipping due date reminder");
        }

        return true; // Assignment notification was dispatched
    }
}

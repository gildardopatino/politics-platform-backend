<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Meetings report
     */
    public function meetings(): JsonResponse
    {
        $tenantId = app('tenant')->id;

        $stats = [
            'total' => Meeting::where('tenant_id', $tenantId)->count(),
            'by_status' => Meeting::where('tenant_id', $tenantId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'upcoming' => Meeting::where('tenant_id', $tenantId)->upcoming()->count(),
            'completed' => Meeting::where('tenant_id', $tenantId)->completed()->count(),
            'total_attendees' => DB::table('meeting_attendees')
                ->join('meetings', 'meetings.id', '=', 'meeting_attendees.meeting_id')
                ->where('meetings.tenant_id', $tenantId)
                ->count(),
            'checked_in_attendees' => DB::table('meeting_attendees')
                ->join('meetings', 'meetings.id', '=', 'meeting_attendees.meeting_id')
                ->where('meetings.tenant_id', $tenantId)
                ->where('meeting_attendees.checked_in', true)
                ->count(),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Campaigns report
     */
    public function campaigns(): JsonResponse
    {
        $tenantId = app('tenant')->id;

        $stats = [
            'total' => Campaign::where('tenant_id', $tenantId)->count(),
            'by_status' => Campaign::where('tenant_id', $tenantId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'by_channel' => Campaign::where('tenant_id', $tenantId)
                ->select('channel', DB::raw('count(*) as total'))
                ->groupBy('channel')
                ->pluck('total', 'channel'),
            'total_sent' => Campaign::where('tenant_id', $tenantId)->sum('sent_count'),
            'total_failed' => Campaign::where('tenant_id', $tenantId)->sum('failed_count'),
            'total_recipients' => Campaign::where('tenant_id', $tenantId)->sum('total_recipients'),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Commitments report
     */
    public function commitments(): JsonResponse
    {
        $tenantId = app('tenant')->id;

        $stats = [
            'total' => Commitment::where('tenant_id', $tenantId)->count(),
            'by_status' => Commitment::where('tenant_id', $tenantId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'overdue' => Commitment::where('tenant_id', $tenantId)->overdue()->count(),
            'completed' => Commitment::where('tenant_id', $tenantId)->completed()->count(),
            'by_priority' => Commitment::where('tenant_id', $tenantId)
                ->join('priorities', 'priorities.id', '=', 'commitments.priority_id')
                ->select('priorities.name', DB::raw('count(*) as total'))
                ->groupBy('priorities.name')
                ->pluck('total', 'name'),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Resources report
     */
    public function resources(): JsonResponse
    {
        $tenantId = app('tenant')->id;

        $stats = [
            'total' => ResourceAllocation::where('tenant_id', $tenantId)->count(),
            'by_type' => ResourceAllocation::where('tenant_id', $tenantId)
                ->select('type', DB::raw('count(*) as total'), DB::raw('sum(amount) as total_amount'))
                ->groupBy('type')
                ->get()
                ->mapWithKeys(fn($item) => [$item->type => [
                    'count' => $item->total,
                    'amount' => $item->total_amount
                ]]),
            'total_cash' => ResourceAllocation::where('tenant_id', $tenantId)->cash()->sum('amount'),
            'total_material' => ResourceAllocation::where('tenant_id', $tenantId)->material()->sum('amount'),
            'total_service' => ResourceAllocation::where('tenant_id', $tenantId)->service()->sum('amount'),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Team performance report
     */
    public function teamPerformance(): JsonResponse
    {
        $tenantId = app('tenant')->id;

        $teamLeaders = User::where('tenant_id', $tenantId)
            ->where('is_team_leader', true)
            ->withCount([
                'plannedMeetings',
                'assignedCommitments',
                'allocatedResources'
            ])
            ->get()
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'meetings_planned' => $user->planned_meetings_count,
                'commitments_assigned' => $user->assigned_commitments_count,
                'resources_allocated' => $user->allocated_resources_count,
            ]);

        return response()->json([
            'data' => $teamLeaders
        ]);
    }
}

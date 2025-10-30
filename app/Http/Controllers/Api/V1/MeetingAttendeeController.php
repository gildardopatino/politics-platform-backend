<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeetingAttendeeResource;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingAttendeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Meeting $meeting): JsonResponse
    {
        $attendees = $meeting->attendees()
            ->when(request('checked_in'), fn($q) => 
                request('checked_in') === 'true' ? $q->checkedIn() : $q->notCheckedIn()
            )
            ->paginate(request('per_page', 50));

        return response()->json([
            'data' => MeetingAttendeeResource::collection($attendees->items()),
            'meta' => [
                'total' => $attendees->total(),
                'current_page' => $attendees->currentPage(),
                'last_page' => $attendees->lastPage(),
                'checked_in_count' => $meeting->attendees()->checkedIn()->count(),
                'total_count' => $meeting->attendees()->count(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'cedula' => 'required|string|max:20',
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'extra_fields' => 'nullable|array',
        ]);

        $attendee = $meeting->attendees()->create($validated);

        return response()->json([
            'data' => new MeetingAttendeeResource($attendee),
            'message' => 'Attendee added successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(MeetingAttendee $attendee): JsonResponse
    {
        $attendee->load('meeting');

        return response()->json([
            'data' => new MeetingAttendeeResource($attendee)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MeetingAttendee $attendee): JsonResponse
    {
        $validated = $request->validate([
            'cedula' => 'sometimes|string|max:20',
            'nombres' => 'sometimes|string|max:255',
            'apellidos' => 'sometimes|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'extra_fields' => 'nullable|array',
            'checked_in' => 'sometimes|boolean',
        ]);

        if (isset($validated['checked_in']) && $validated['checked_in'] && !$attendee->checked_in) {
            $validated['checked_in_at'] = now();
        }

        $attendee->update($validated);

        return response()->json([
            'data' => new MeetingAttendeeResource($attendee),
            'message' => 'Attendee updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MeetingAttendee $attendee): JsonResponse
    {
        $attendee->delete();

        return response()->json([
            'message' => 'Attendee deleted successfully'
        ]);
    }
}

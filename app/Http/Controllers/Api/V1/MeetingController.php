<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Meeting\CheckInRequest;
use App\Http\Requests\Api\V1\Meeting\StoreMeetingRequest;
use App\Http\Requests\Api\V1\Meeting\UpdateMeetingRequest;
use App\Http\Resources\Api\V1\MeetingResource;
use App\Jobs\Meetings\GenerateQRCodeJob;
use App\Models\Meeting;
use App\Services\QRCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class MeetingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $meetings = QueryBuilder::for(Meeting::class)
            ->allowedFilters(['title', 'status', 'department_id', 'municipality_id', 'commune_id', 'barrio_id'])
            ->allowedIncludes(['planner', 'template', 'attendees', 'commitments', 'department', 'municipality', 'commune', 'barrio', 'corregimiento', 'vereda'])
            ->allowedSorts(['starts_at', 'created_at', 'title', 'status'])
            ->with(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])
            ->withCount(['attendees', 'commitments'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => MeetingResource::collection($meetings->items()),
            'meta' => [
                'total' => $meetings->total(),
                'current_page' => $meetings->currentPage(),
                'last_page' => $meetings->lastPage(),
                'per_page' => $meetings->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMeetingRequest $request, QRCodeService $qrCodeService): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $meeting = Meeting::create([
            'tenant_id' => $user->tenant_id,
            'planner_user_id' => $user->id,
            ...$request->validated()
        ]);

        // Generate QR code synchronously
        $qrData = $qrCodeService->generateForMeeting(
            $meeting->id,
            $meeting->tenant->slug
        );

        $meeting->update(['qr_code' => $qrData['code']]);
        $meeting->qr_data = $qrData; // Attach QR data temporarily for response

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Meeting $meeting): JsonResponse
    {
        $meeting->load(['planner', 'template', 'attendees', 'commitments', 'department', 'municipality', 'commune', 'barrio', 'corregimiento', 'vereda']);
        
        return response()->json([
            'data' => new MeetingResource($meeting)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $meeting->update($request->validated());

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Meeting $meeting): JsonResponse
    {
        $meeting->delete();

        return response()->json([
            'message' => 'Meeting deleted successfully'
        ]);
    }

    /**
     * Complete a meeting
     */
    public function complete(Meeting $meeting): JsonResponse
    {
        $meeting->update([
            'status' => 'completed',
            'ends_at' => now()
        ]);

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting marked as completed'
        ]);
    }

    /**
     * Cancel a meeting
     */
    public function cancel(Meeting $meeting): JsonResponse
    {
        $meeting->update(['status' => 'cancelled']);

        return response()->json([
            'data' => new MeetingResource($meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template'])),
            'message' => 'Meeting cancelled'
        ]);
    }

    /**
     * Get QR code for meeting
     */
    public function getQRCode(Meeting $meeting, QRCodeService $qrCodeService): JsonResponse
    {
        if (!$meeting->qr_code) {
            return response()->json([
                'message' => 'QR code not generated yet'
            ], 404);
        }

        $qrData = $qrCodeService->getQRCodeBase64($meeting->qr_code);
        $qrPath = $qrCodeService->getQRCodePath($meeting->qr_code, $meeting->tenant->slug);

        return response()->json([
            'qr_code' => $meeting->qr_code,
            'qr_url' => $qrPath ? asset("storage/{$qrPath}") : null,
            'check_in_url' => $qrData['url'],
            'svg' => $qrData['svg'],
            'svg_base64' => $qrData['svg_base64'],
        ]);
    }

    /**
     * Show meeting by QR code (public)
     */
    public function showByQR(string $qrCode): JsonResponse
    {
        $meeting = Meeting::where('qr_code', $qrCode)->firstOrFail();
        $meeting->load(['planner', 'department', 'municipality', 'commune', 'barrio', 'template']);

        return response()->json([
            'data' => new MeetingResource($meeting)
        ]);
    }

    /**
     * Check in to meeting via QR code (public)
     */
    public function checkIn(string $qrCode, CheckInRequest $request): JsonResponse
    {
        $meeting = Meeting::where('qr_code', $qrCode)->firstOrFail();

        $attendee = $meeting->attendees()->create([
            ...$request->validated(),
            'created_by' => $request->user()?->id,
            'checked_in' => true,
            'checked_in_at' => now()
        ]);

        return response()->json([
            'data' => $attendee,
            'message' => 'Check-in successful'
        ], 201);
    }
}

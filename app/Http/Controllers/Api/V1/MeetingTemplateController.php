<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MeetingTemplate\StoreMeetingTemplateRequest;
use App\Http\Requests\Api\V1\MeetingTemplate\UpdateMeetingTemplateRequest;
use App\Http\Resources\Api\V1\MeetingTemplateResource;
use App\Models\MeetingTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class MeetingTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $templates = QueryBuilder::for(MeetingTemplate::class)
            ->allowedFilters(['name'])
            ->allowedSorts(['name', 'created_at'])
            ->withCount('meetings')
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => MeetingTemplateResource::collection($templates->items()),
            'meta' => [
                'total' => $templates->total(),
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMeetingTemplateRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $template = MeetingTemplate::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$request->validated()
        ]);

        return response()->json([
            'data' => new MeetingTemplateResource($template),
            'message' => 'Template created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(MeetingTemplate $meetingTemplate): JsonResponse
    {
        $meetingTemplate->loadCount('meetings');

        return response()->json([
            'data' => new MeetingTemplateResource($meetingTemplate)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMeetingTemplateRequest $request, MeetingTemplate $meetingTemplate): JsonResponse
    {
        $meetingTemplate->update($request->validated());

        return response()->json([
            'data' => new MeetingTemplateResource($meetingTemplate),
            'message' => 'Template updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MeetingTemplate $meetingTemplate): JsonResponse
    {
        $meetingTemplate->delete();

        return response()->json([
            'message' => 'Template deleted successfully'
        ]);
    }
}

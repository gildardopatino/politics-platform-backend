<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaign\StoreCampaignRequest;
use App\Http\Requests\Api\V1\Campaign\UpdateCampaignRequest;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignService $campaignService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $campaigns = QueryBuilder::for(Campaign::class)
            ->allowedFilters(['titulo', 'status', 'channel'])
            ->allowedIncludes(['createdBy', 'recipients'])
            ->allowedSorts(['created_at', 'scheduled_at', 'titulo'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => CampaignResource::collection($campaigns->items()),
            'meta' => [
                'total' => $campaigns->total(),
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaignService->createCampaign($request->validated());

        return response()->json([
            'data' => new CampaignResource($campaign->load('createdBy')),
            'message' => 'Campaign created and queued for sending'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->load(['createdBy', 'recipients']);

        return response()->json([
            'data' => new CampaignResource($campaign)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'pending') {
            return response()->json([
                'message' => 'Cannot update campaign that is not pending'
            ], 422);
        }

        $campaign->update($request->validated());

        return response()->json([
            'data' => new CampaignResource($campaign),
            'message' => 'Campaign updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === 'in_progress') {
            return response()->json([
                'message' => 'Cannot delete campaign in progress'
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'message' => 'Campaign deleted successfully'
        ]);
    }

    /**
     * Send campaign manually
     */
    public function send(Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'pending') {
            return response()->json([
                'message' => 'Campaign is not in pending status'
            ], 422);
        }

        SendCampaignJob::dispatch($campaign);

        return response()->json([
            'message' => 'Campaign queued for sending'
        ]);
    }

    /**
     * Cancel campaign
     */
    public function cancel(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === 'completed') {
            return response()->json([
                'message' => 'Cannot cancel completed campaign'
            ], 422);
        }

        $campaign->update(['status' => 'cancelled']);

        return response()->json([
            'data' => new CampaignResource($campaign),
            'message' => 'Campaign cancelled'
        ]);
    }

    /**
     * Get campaign recipients
     */
    public function recipients(Campaign $campaign): JsonResponse
    {
        $recipients = $campaign->recipients()
            ->paginate(request('per_page', 50));

        return response()->json([
            'data' => CampaignRecipientResource::collection($recipients->items()),
            'meta' => [
                'total' => $recipients->total(),
                'current_page' => $recipients->currentPage(),
                'last_page' => $recipients->lastPage(),
            ]
        ]);
    }
}

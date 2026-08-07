<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CampaignHasNoRecipientsException;
use App\Exceptions\InsufficientMessagingCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaign\StoreCampaignRequest;
use App\Http\Requests\Api\V1\Campaign\UpdateCampaignRequest;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * Crear una campaña la deja en **borrador**: se guarda el mensaje, el canal
     * y los filtros, y no sale nada hasta que alguien pulse enviar (Spec 0040).
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaignService->createCampaign($request->validated());

        return response()->json([
            'data' => new CampaignResource($campaign->load('createdBy')),
            'message' => 'Campaign saved as draft',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->load(['createdBy', 'recipients']);

        return response()->json([
            'data' => new CampaignResource($campaign),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Solo se edita el borrador. Antes se exigía `pending`, que era el estado en
     * el que el alta dejaba la campaña **ya encolada**: se podía tocar algo que
     * estaba a punto de salir, y en cambio no una programada (Spec 0040).
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json([
                'message' => 'Cannot update campaign that is not a draft',
            ], 422);
        }

        $campaign->update($request->validated());

        return response()->json([
            'data' => new CampaignResource($campaign),
            'message' => 'Campaign updated successfully',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        // El estado real mientras se envía es `sending`; la guarda comparaba con
        // `in_progress`, que nadie escribe, así que no protegía nada y se podía
        // borrar una campaña con el job a medio recorrer (Spec 0038).
        if ($campaign->status === 'sending') {
            return response()->json([
                'message' => 'Cannot delete campaign in progress',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'message' => 'Campaign deleted successfully',
        ]);
    }

    /**
     * Send campaign manually
     *
     * El alta ya despacha el envío y deja la campaña en `pending`, así que este
     * endpoint encolaba un **segundo** job de la misma campaña: si el primero no
     * había corrido, los dos veían destinatarios `pending` y la gente recibía el
     * mensaje dos veces (Spec 0038).
     *
     * Una campaña se despacha **una sola vez**: `queued_at` marca que ya hay un
     * job para ella y este endpoint no vuelve a encolar. Queda como disparador
     * de las campañas que, por lo que sea, no llegaron a encolarse.
     */
    public function send(Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json([
                'message' => 'Campaign is not a draft',
            ], 422);
        }

        // Cinturón y tirantes: una campaña en borrador no debería tener despacho
        // anotado, pero si lo tuviera no se encola un segundo (Spec 0038).
        if ($campaign->queued_at !== null) {
            return response()->json([
                'message' => 'Campaign was already queued for sending',
            ], 422);
        }

        try {
            $campaign = $this->campaignService->dispatchCampaign($campaign);
        } catch (CampaignHasNoRecipientsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (InsufficientMessagingCreditsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'credits' => $e->detalle(),
            ], 422);
        }

        return response()->json([
            'data' => new CampaignResource($campaign),
            'message' => 'Campaign queued for sending',
        ]);
    }

    /**
     * Cancel campaign
     *
     * Cancelar detiene lo que todavía no ha salido: la campaña queda
     * `cancelled` —estado que el job comprueba antes de arrancar, así que una
     * campaña programada ya no se envía— y los destinatarios que seguían
     * `pending` se marcan como cancelados, que es su desenlace.
     *
     * La guarda mira `sent`, el estado terminal real. Antes comparaba con
     * `completed`, que nadie escribe nunca, así que ni siquiera frenaba una
     * campaña ya enviada (Spec 0038).
     */
    public function cancel(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === 'sent') {
            return response()->json([
                'message' => 'Cannot cancel a campaign that was already sent',
            ], 422);
        }

        DB::transaction(function () use ($campaign) {
            $campaign->recipients()
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            $campaign->update(['status' => 'cancelled']);
        });

        return response()->json([
            'data' => new CampaignResource($campaign->refresh()),
            'message' => 'Campaign cancelled',
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
            ],
        ]);
    }
}

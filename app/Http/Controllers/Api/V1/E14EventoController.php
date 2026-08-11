<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectoralEventResource;
use App\Models\ElectoralEvent;
use Illuminate\Http\JsonResponse;

/**
 * Las elecciones del tenant (Specs 0061 y 0062).
 *
 * Queda como **lectura**: de aquí sale la lista con la que el cruce y el
 * consolidado eligen de qué elección se consulta. Configurar el candidato ya no
 * se hace por elección — la 0080 lo movió a `/e14/candidato`, uno por campaña,
 * porque fijarlo por elección permitía ponerlo en una que no es la del cargo del
 * tenant y obligaba a teclear una identidad que ya estaba guardada.
 */
class E14EventoController extends Controller
{
    /**
     * Las elecciones del tenant, con su candidato propio y el tarjetón.
     */
    public function index(): JsonResponse
    {
        $eventos = ElectoralEvent::query()
            ->with('candidates')
            ->withCount('actas')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => ElectoralEventResource::collection($eventos),
        ]);
    }
}

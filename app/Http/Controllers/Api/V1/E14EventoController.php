<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UpdateCandidatoPropioRequest;
use App\Http\Resources\Api\V1\ElectoralEventResource;
use App\Models\E14Candidate;
use App\Models\ElectoralEvent;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de campaña de una elección (Spec 0062 · Parte 0).
 *
 * Lo único que se configura hoy es **quién es mi candidato**: el número del
 * tarjetón con el que el cruce sabe qué fila del E-14 le pertenece a esta
 * campaña. El resto de la elección (nombre, tipo, fecha) lo crea sola la ingesta.
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

    /**
     * Fija (o borra) el candidato propio de una elección.
     *
     * Cuando el catálogo ya existe, el nombre y la agrupación se completan desde
     * él si no vienen en la petición: lo que el acta dice del candidato es más
     * fiable que lo que alguien recuerde al teclearlo, y así la pantalla puede
     * mandar solo el número.
     */
    public function updateCandidatoPropio(
        UpdateCandidatoPropioRequest $request,
        ElectoralEvent $evento
    ): JsonResponse {
        $numero = $request->input('numero') === null ? null : (int) $request->input('numero');

        $delCatalogo = $numero === null
            ? null
            : E14Candidate::where('electoral_event_id', $evento->id)->where('numero', $numero)->first();

        $evento->update([
            'candidato_propio_numero' => $numero,
            // Borrar el número borra su ficha: dejar el nombre suelto de un
            // candidato que ya no se cruza solo confunde a quien lo lea después.
            'candidato_propio_nombre' => $numero === null
                ? null
                : ($request->input('nombre') ?: $delCatalogo?->nombre),
            'candidato_propio_agrupacion' => $numero === null
                ? null
                : ($request->input('agrupacion') ?: $delCatalogo?->agrupacion),
        ]);

        return response()->json([
            'data' => new ElectoralEventResource($evento->load('candidates')->loadCount('actas')),
            'message' => $numero === null
                ? 'Se quitó el candidato propio de esta elección: el cruce queda sin calcular.'
                : "Candidato propio de esta elección: número {$numero}.",
        ]);
    }
}

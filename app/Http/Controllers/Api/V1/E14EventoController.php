<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectoralEventResource;
use App\Models\ElectoralEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Las elecciones **de la campaña**, con su candidato propio y el tarjetón.
     *
     * Acotado al tipo del tenant (Spec 0093): una campaña sirve a una sola
     * elección, así que las jornadas de otro tipo que hubiera cargadas —viejas
     * o de prueba— no se ofrecen. Listarlas era enseñar una opción que, al
     * elegirla, devuelve un 422 («esa elección es de gobernación y esta campaña
     * escruta alcaldía») o una tabla vacía: parecía que se habían perdido los
     * datos.
     *
     * Un cargo `Otro` no escruta y recibe la **lista vacía**, no un error: el
     * menú del escrutinio ni siquiera se le enseña (Parte C), y un 422 aquí
     * convertiría en avería lo que es simplemente no tener el módulo.
     */
    public function index(Request $request): JsonResponse
    {
        // El mismo helper del que sale el tipo en la carga, el cruce y el
        // consolidado: `Tenant::ELECCION_POR_CARGO` es la única tabla que
        // traduce cargo → elección (Spec 0093).
        $tipo = $request->user()?->tenant?->tipoEleccion();

        $eventos = $tipo === null
            ? collect()
            : ElectoralEvent::query()
                ->where('tipo', $tipo)
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

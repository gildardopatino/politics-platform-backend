<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\EstadisticasRequest;
use App\Services\E14\EstadisticasService;
use App\Services\E14\EventoResolver;
use Illuminate\Http\JsonResponse;

/**
 * Estadísticas del escrutinio (Spec 0092 · Parte A).
 *
 * Solo lectura y de una sola pieza: devuelve la fila de **cada mesa** con lo del
 * candidato (base, votos, déficit) y lo de la mesa (zona, urna, sufragantes),
 * para que la página de exploración haga sus roll-ups y su drill-down en memoria
 * sin una petición por nivel.
 */
class E14EstadisticasController extends Controller
{
    public function __construct(
        private readonly EstadisticasService $estadisticas,
        private readonly EventoResolver $eventos,
    ) {}

    public function index(EstadisticasRequest $request): JsonResponse
    {
        $evento = $this->eventos->delTipo(
            (string) $request->input('tipo'),
            $request->filled('event') ? $request->integer('event') : null,
        );

        return response()->json($this->estadisticas->calcular($evento, $request->validated()));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\CruceRequest;
use App\Services\E14\CruceService;
use App\Services\E14\EventoResolver;
use Illuminate\Http\JsonResponse;

/**
 * Cruce potencial vs real (Spec 0062 · Parte A).
 *
 * Registrados del tenant contra los votos reales de su candidato, por puesto o
 * por mesa. Se calcula al preguntarlo: cambiar el candidato propio o fusionar dos
 * puestos se ve en la siguiente llamada, sin recalcular nada guardado.
 */
class E14CruceController extends Controller
{
    public function __construct(
        private readonly CruceService $cruce,
        private readonly EventoResolver $eventos,
    ) {}

    public function index(CruceRequest $request): JsonResponse
    {
        $evento = $this->eventos->delTenant(
            $request->filled('event') ? $request->integer('event') : null
        );

        return response()->json($this->cruce->calcular($evento, $request->validated()));
    }
}

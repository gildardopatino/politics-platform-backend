<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\ProyeccionRequest;
use App\Services\E14\EventoResolver;
use App\Services\E14\ProyeccionService;
use Illuminate\Http\JsonResponse;

/**
 * Proyección «¿voy ganando?» (Spec 0064 · Parte A).
 *
 * Meta fijada a mano contra base identificada (0076) contra votos reales
 * (0062/0083), por puesto, municipio o global. Se calcula al preguntarlo: mover
 * la meta o cargar un acta se ve en la siguiente llamada, sin nada guardado que
 * recalcular.
 */
class E14ProyeccionController extends Controller
{
    public function __construct(
        private readonly ProyeccionService $proyeccion,
        private readonly EventoResolver $eventos,
    ) {}

    public function index(ProyeccionRequest $request): JsonResponse
    {
        $evento = $this->eventos->delTenant(
            $request->filled('event') ? $request->integer('event') : null
        );

        return response()->json($this->proyeccion->calcular($evento, $request->validated()));
    }
}

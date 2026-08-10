<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\RendimientoLideresRequest;
use App\Services\E14\EventoResolver;
use App\Services\E14\RendimientoLideresService;
use Illuminate\Http\JsonResponse;

/**
 * Rendimiento operativo del líder (Spec 0063 · Parte A).
 *
 * Lo que un líder produjo —reuniones, check-ins, gente de la que sabemos dónde
 * vota— junto al rendimiento de sus mesas como **proxy declarado**. Aquí no hay
 * «votos del líder»: el voto es secreto y las mesas se comparten.
 *
 * Se calcula al preguntarlo, como el cruce: nada se guarda.
 */
class E14RendimientoLideresController extends Controller
{
    public function __construct(
        private readonly RendimientoLideresService $rendimiento,
        private readonly EventoResolver $eventos,
    ) {}

    public function index(RendimientoLideresRequest $request): JsonResponse
    {
        $evento = $this->eventos->delTenant(
            $request->filled('event') ? $request->integer('event') : null
        );

        return response()->json($this->rendimiento->calcular($evento, $request->validated()));
    }
}

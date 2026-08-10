<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\FusionarPuestosRequest;
use App\Services\E14\ConciliacionService;
use App\Services\E14\EventoResolver;
use App\Services\E14\RegistradosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conciliación de puestos (Spec 0062 · Parte A).
 *
 * Lo que la normalización no pudo unir se lista aquí para que **una persona**
 * decida. El servidor no fusiona por parecido: ofrece candidatos ordenados y
 * ejecuta lo que le pidan.
 */
class E14PuestoController extends Controller
{
    public function __construct(
        private readonly ConciliacionService $conciliacion,
        private readonly EventoResolver $eventos,
    ) {}

    public function porConciliar(Request $request): JsonResponse
    {
        return response()->json($this->conciliacion->pendientes(
            $this->eventos->delTenant($request->filled('event') ? $request->integer('event') : null),
            RegistradosService::normalizarIncluir($request->input('incluir')),
        ));
    }

    public function fusionar(FusionarPuestosRequest $request): JsonResponse
    {
        $resultado = $this->conciliacion->fusionar(
            tenantId: $request->user()->tenant_id,
            destinoId: (int) $request->input('destino_id'),
            origenId: $request->filled('origen_id') ? (int) $request->input('origen_id') : null,
            municipio: $request->input('municipio'),
            puesto: $request->input('puesto'),
        );

        return response()->json([
            'data' => $resultado,
            'message' => "«{$resultado['nombre_fusionado']}» ahora cuenta en «{$resultado['puesto']}».",
        ]);
    }
}

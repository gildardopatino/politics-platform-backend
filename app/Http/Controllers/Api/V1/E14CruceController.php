<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\CruceRequest;
use App\Models\ElectoralEvent;
use App\Services\E14\CruceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Cruce potencial vs real (Spec 0062 · Parte A).
 *
 * Registrados del tenant contra los votos reales de su candidato, por puesto o
 * por mesa. Se calcula al preguntarlo: cambiar el candidato propio o fusionar dos
 * puestos se ve en la siguiente llamada, sin recalcular nada guardado.
 */
class E14CruceController extends Controller
{
    public function __construct(private readonly CruceService $cruce) {}

    public function index(CruceRequest $request): JsonResponse
    {
        return response()->json(
            $this->cruce->calcular($this->evento($request), $request->validated())
        );
    }

    /**
     * De qué elección se cruza.
     *
     * Sin `event` se toma la más reciente del tenant: es lo que una campaña con
     * una sola elección cargada —el caso normal— espera ver sin tener que
     * elegirla. La búsqueda va con `TenantScope`, así que una elección de otra
     * campaña sencillamente no existe desde aquí.
     */
    private function evento(CruceRequest $request): ElectoralEvent
    {
        if ($request->filled('event')) {
            $evento = ElectoralEvent::find($request->integer('event'));

            if (! $evento) {
                throw ValidationException::withMessages([
                    'event' => 'La elección indicada no existe en esta campaña.',
                ]);
            }

            return $evento;
        }

        $evento = ElectoralEvent::query()->orderByDesc('fecha')->orderByDesc('id')->first();

        if (! $evento) {
            throw ValidationException::withMessages([
                'event' => 'Todavía no hay ninguna elección cargada en esta campaña.',
            ]);
        }

        return $evento;
    }
}

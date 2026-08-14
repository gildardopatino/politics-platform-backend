<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UpdateMetaRequest;
use App\Models\E14MetaPuesto;
use App\Models\ElectoralEvent;
use App\Services\E14\EventoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La meta de votos de la campaña (Spec 0064 · RF-1).
 *
 * La meta se fija **a mano**: es la decisión del jefe de campaña sobre a qué
 * aspira, no un número derivado del histórico ni del censo. Hay una global por
 * elección y overrides opcionales por puesto; municipio y zona no se fijan, se
 * **agregan** de sus puestos.
 *
 * Escribirla pide `manage_e14` y queda auditada: mueve el semáforo de todo el
 * tablero de proyección.
 */
class E14MetaController extends Controller
{
    public function __construct(private readonly EventoResolver $eventos) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->ficha($this->evento($request)));
    }

    /**
     * Fija la meta global, las de puesto, o las dos.
     *
     * Es un `PUT` **parcial**: la clave que no viene no se toca. La pantalla edita
     * una casilla a la vez y exigirle reenviar el estado entero convertiría cada
     * ajuste en una ocasión de pisar lo que otro acababa de guardar.
     */
    public function update(UpdateMetaRequest $request): JsonResponse
    {
        $evento = $this->evento($request);

        // `has()` y no `filled()`: `meta_votos: null` es una orden —«quítala»— y
        // no la ausencia de la clave.
        if ($request->has('meta_votos')) {
            $evento->update(['meta_votos' => $request->input('meta_votos')]);
        }

        foreach ($request->input('puestos', []) as $fila) {
            $this->fijarPuesto($evento, (int) $fila['voting_place_id'], $fila['meta_votos']);
        }

        return response()->json(
            $this->ficha($evento->refresh()) + ['message' => 'Se guardó la meta de la campaña.']
        );
    }

    /**
     * La meta de un puesto: se crea, se actualiza o se borra.
     *
     * Quitarla **borra la fila** en vez de guardar un cero: «no fijé meta aquí» y
     * «mi meta aquí es cero votos» son estados distintos, y el segundo pintaría de
     * verde un puesto donde la campaña no se propuso nada.
     */
    private function fijarPuesto(ElectoralEvent $evento, int $puesto, mixed $meta): void
    {
        $llaves = ['electoral_event_id' => $evento->id, 'voting_place_id' => $puesto];

        if ($meta === null) {
            E14MetaPuesto::query()->where($llaves)->delete();

            return;
        }

        // `tenant_id` lo pone `HasTenant` al crear, y `TenantScope` acota la
        // búsqueda: la meta de otra campaña no existe desde aquí.
        E14MetaPuesto::updateOrCreate($llaves, ['meta_votos' => (int) $meta]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(ElectoralEvent $evento): array
    {
        $puestos = E14MetaPuesto::query()
            ->where('electoral_event_id', $evento->id)
            ->with('puesto:id,departamento_votacion,municipio_votacion,puesto_votacion')
            ->get()
            ->map(fn (E14MetaPuesto $meta) => [
                'voting_place_id' => $meta->voting_place_id,
                'departamento' => $meta->puesto?->departamento_votacion,
                'municipio' => $meta->puesto?->municipio_votacion,
                'puesto' => $meta->puesto?->puesto_votacion,
                'meta_votos' => $meta->meta_votos,
            ])
            ->sortBy(fn (array $fila) => [$fila['municipio'], $fila['puesto']])
            ->values()
            ->all();

        $asignada = (int) array_sum(array_column($puestos, 'meta_votos'));

        return [
            'data' => [
                'electoral_event_id' => $evento->id,
                'meta_votos' => $evento->meta_votos,
                'puestos' => $puestos,
            ],
            'meta' => [
                'meta_asignada' => $asignada,
                // Lo que falta por repartir entre los puestos. Sin meta global no
                // hay nada que repartir, y un 0 ahí se leería como «ya está toda
                // asignada». Puede quedar en 0 por exceso: asignar más que la
                // meta global no es un error, es una campaña que se cubre.
                'sin_asignar' => $evento->meta_votos === null
                    ? null
                    : max(0, $evento->meta_votos - $asignada),
                'puestos_con_meta' => count($puestos),
            ],
        ];
    }

    private function evento(Request $request): ElectoralEvent
    {
        return $this->eventos->delTenant(
            $request->filled('event') ? $request->integer('event') : null
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UpdateCandidatoDelTenantRequest;
use App\Http\Resources\Api\V1\CandidatoDelTenantResource;
use App\Models\E14Candidate;
use App\Models\Tenant;
use App\Services\E14\EventoResolver;
use Illuminate\Http\JsonResponse;

/**
 * «Mi candidato», uno por campaña (Spec 0080).
 *
 * La 0062 configuraba el candidato **por elección**, y con ello la pantalla
 * ofrecía tantas fichas como elecciones hubiera cargadas. Eso contradice el
 * modelo: un tenant es la campaña de un candidato a un cargo, y ese cargo ya está
 * en `tenants.tipo_cargo`. Aquí hay una sola ficha, su identidad sale del tenant,
 * y el número del tarjetón se escribe en la elección de ese cargo — la única
 * donde tiene sentido.
 *
 * Lo que **no** cambia es dónde vive el número: sigue en
 * `electoral_events.candidato_propio_*`, que es donde el cruce (0062), el déficit
 * (0076) y el rendimiento de líderes (0063) lo leen. Este controlador cambia el
 * flujo, no el modelo, para no tocar tres lectores por una pantalla.
 */
class E14CandidatoController extends Controller
{
    public function __construct(private readonly EventoResolver $eventos) {}

    /**
     * La ficha del candidato: identidad del tenant, número de la elección del cargo.
     */
    public function show(): JsonResponse
    {
        $tenant = $this->tenant();

        if (! $tenant instanceof Tenant) {
            return $this->sinCampana();
        }

        $evento = $this->eventos->delCargo($tenant);

        return response()->json(
            (new CandidatoDelTenantResource(
                $tenant,
                $evento?->load('candidates'),
                $this->eventos->tipoDelCargo($tenant->tipo_cargo),
            ))->response()->getData(true)
        );
    }

    /**
     * Fija (o borra) el número del tarjetón.
     *
     * El nombre se copia desde el tenant en la misma escritura: es lo que hace
     * que los lectores del cruce sigan encontrando al candidato donde siempre,
     * sin enterarse de que la pantalla cambió. La agrupación se completa desde el
     * tarjetón cuando no viene en la petición —lo que el acta dice del partido es
     * más fiable que lo que alguien recuerde al teclearlo—, pero el **nombre no**:
     * ese es el del tenant, aunque el acta lo escriba distinto.
     */
    public function update(UpdateCandidatoDelTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenant();

        if (! $tenant instanceof Tenant) {
            return $this->sinCampana();
        }

        $numero = $request->input('numero') === null ? null : (int) $request->input('numero');

        // Se crea al vuelo si aún no existe: el número del tarjetón se sabe
        // antes que la primera acta (find-or-create, como la ingesta).
        $evento = $this->eventos->paraElCargo($tenant);

        $delCatalogo = $numero === null
            ? null
            : E14Candidate::where('electoral_event_id', $evento->id)->where('numero', $numero)->first();

        $evento->update([
            'candidato_propio_numero' => $numero,
            // Borrar el número borra la ficha: dejar el nombre suelto de un
            // candidato que ya no se cruza solo confunde a quien lo lea después.
            'candidato_propio_nombre' => $numero === null ? null : $tenant->nombre,
            'candidato_propio_agrupacion' => $numero === null
                ? null
                : ($request->input('agrupacion') ?: $delCatalogo?->agrupacion),
        ]);

        return response()->json(
            (new CandidatoDelTenantResource(
                $tenant,
                $evento->load('candidates'),
                $this->eventos->tipoDelCargo($tenant->tipo_cargo),
            ))->response()->getData(true)
                + ['message' => $numero === null
                    ? 'Se quitó el número del tarjetón: el cruce queda sin calcular.'
                    : "Tu candidato quedó fijado con el número {$numero}."]
        );
    }

    private function tenant(): ?Tenant
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * Un super admin no tiene campaña, y esta ficha **es** la de una campaña: sin
     * tenant no hay ni nombre ni cargo de los que partir.
     */
    private function sinCampana(): JsonResponse
    {
        return response()->json([
            'message' => 'Esta configuración pertenece a una campaña; tu sesión no está asociada a ninguna.',
        ], 403);
    }
}

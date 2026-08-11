<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\E14\UpdateCandidatoDelTenantRequest;
use App\Http\Resources\Api\V1\CandidatoDelTenantResource;
use App\Models\E14Acta;
use App\Models\E14Candidate;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Services\E14\CatalogoDeCorporacion;
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
    public function __construct(
        private readonly EventoResolver $eventos,
        private readonly CatalogoDeCorporacion $catalogo,
    ) {}

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

        return response()->json($this->ficha($tenant, $evento));
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
        $corporacion = E14Acta::esCorporacion($evento->tipo);

        // En corporación el candidato vive **dentro** de una lista, así que el
        // par viaja junto; en uninominal la lista se queda en `null`, que es lo
        // que hace que el cruce (0062) y el rendimiento (0063) sigan leyendo lo
        // de siempre sin enterarse de que existe una dimensión más.
        $lista = $numero !== null && $corporacion
            ? (int) $request->input('lista_numero')
            : null;

        $evento->update([
            'candidato_propio_numero' => $numero,
            'candidato_propio_lista_numero' => $lista,
            // Borrar el número borra la ficha: dejar el nombre suelto de un
            // candidato que ya no se cruza solo confunde a quien lo lea después.
            'candidato_propio_nombre' => $numero === null ? null : $tenant->nombre,
            'candidato_propio_agrupacion' => $numero === null
                ? null
                : ($request->input('agrupacion') ?: $this->partido($evento, $corporacion, $lista, $numero)),
        ]);

        return response()->json(
            $this->ficha($tenant, $evento)
                + ['message' => $this->mensaje($numero, $lista)]
        );
    }

    /**
     * El partido del candidato, cuando el cliente no lo manda.
     *
     * En uninominal sale del tarjetón (`e14_candidates`); en corporación, del
     * **nombre de la lista elegida** (RF-1), que es lo mismo dicho al nivel que
     * corresponde. En los dos casos, lo que el acta dice del partido es más
     * fiable que lo que alguien recuerde al teclearlo.
     */
    private function partido(
        ElectoralEvent $evento,
        bool $corporacion,
        ?int $lista,
        int $numero
    ): ?string {
        if ($corporacion) {
            return $lista === null
                ? null
                : $this->catalogo->nombreDeLista($this->catalogo->paraEvento($evento), $lista);
        }

        return E14Candidate::where('electoral_event_id', $evento->id)
            ->where('numero', $numero)
            ->first()?->agrupacion;
    }

    private function mensaje(?int $numero, ?int $lista): string
    {
        if ($numero === null) {
            return 'Se quitó el número del tarjetón: el cruce queda sin calcular.';
        }

        return $lista === null
            ? "Tu candidato quedó fijado con el número {$numero}."
            : "Tu candidato quedó fijado en la lista {$lista} con el número de preferencia {$numero}.";
    }

    /**
     * La ficha, con el catálogo que le toca al cargo.
     *
     * @return array<string, mixed>
     */
    private function ficha(Tenant $tenant, ?ElectoralEvent $evento): array
    {
        $tipo = $this->eventos->tipoDelCargo($tenant->tipo_cargo);

        return (new CandidatoDelTenantResource(
            $tenant,
            $evento?->load('candidates'),
            $tipo,
            E14Acta::esCorporacion($tipo) ? $this->catalogo->paraEvento($evento) : [],
        ))->response()->getData(true);
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

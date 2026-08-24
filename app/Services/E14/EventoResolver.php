<?php

namespace App\Services\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Validation\ValidationException;

/**
 * A qué elección pertenece un acta (Specs 0061 y 0071).
 *
 * Si el cliente no la nombra se resuelve por tipo y se crea al vuelo. Exigir que
 * alguien dé de alta la elección antes de poder subir un acta sería un paso de
 * configuración que la noche del escrutinio nadie va a dar; el `firstOrCreate`
 * sobre la clave natural evita que se multipliquen.
 *
 * Vive aparte porque ahora hay dos puertas de entrada —la ingesta directa del
 * lector y la carga desde el panel— y la regla tiene que ser la misma en las dos.
 */
class EventoResolver
{
    /**
     * De qué cargo del tenant es cada tipo de elección (Spec 0080).
     *
     * Los dos campos hablan idiomas distintos por historia: `tenants.tipo_cargo`
     * es el enum del alta de campañas (capitalizado, sin tildes) y
     * `electoral_events.tipo` es el vocabulario del E-14 (`E14Acta::TIPOS`).
     * Traducir por texto —minúsculas y sin tildes— acertaría en tres casos y
     * fallaría en los dos que importan: un **diputado** se elige en la
     * `asamblea_departamental`, y un **congresista**, en el `senado`.
     *
     * Lo que vale `null` no se adivina: `Otro` no es un cargo de elección
     * popular, y la Cámara de Representantes todavía no tiene tipo de acta. Con
     * un cargo así no hay elección donde fijar el número, y quien pregunte
     * recibe el aviso en vez de una escritura en la elección equivocada.
     *
     * @var array<string, string|null>
     */
    public const TIPO_POR_CARGO = [
        'alcaldia' => 'alcaldia',
        'gobernacion' => 'gobernacion',
        'concejo' => 'concejo',
        'diputado' => 'asamblea_departamental',
        'congresista' => 'senado',
        'otro' => null,
    ];

    /**
     * @param  array<string, mixed>  $datos
     */
    public function resolver(array $datos, int $tenantId): ElectoralEvent
    {
        if (! empty($datos['electoral_event_id'])) {
            // La búsqueda va con `TenantScope`: una elección de otro tenant
            // sencillamente no existe desde aquí.
            $evento = ElectoralEvent::find($datos['electoral_event_id']);

            if (! $evento) {
                throw ValidationException::withMessages([
                    'electoral_event_id' => 'La elección indicada no existe en esta campaña.',
                ]);
            }

            return $evento;
        }

        $tipo = $datos['tipo'];

        return ElectoralEvent::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'tipo' => $tipo,
                'nombre' => $datos['evento_nombre'] ?? $this->nombrePorDefecto($tipo),
            ],
            ['fecha' => $datos['evento_fecha'] ?? null],
        );
    }

    /**
     * De qué elección se consulta (Spec 0062).
     *
     * Sin id se toma la más reciente del tenant: es lo que una campaña con una
     * sola elección cargada —el caso normal— espera ver sin tener que elegirla.
     * Aquí nunca se crea nada: consultar no es dar de alta.
     */
    public function delTenant(?int $id): ElectoralEvent
    {
        // Las búsquedas van con `TenantScope`: una elección de otra campaña
        // sencillamente no existe desde aquí.
        $evento = $id !== null
            ? ElectoralEvent::find($id)
            : ElectoralEvent::query()->orderByDesc('fecha')->orderByDesc('id')->first();

        if ($evento) {
            return $evento;
        }

        throw ValidationException::withMessages([
            'event' => $id !== null
                ? 'La elección indicada no existe en esta campaña.'
                : 'Todavía no hay ninguna elección cargada en esta campaña.',
        ]);
    }

    /**
     * De qué elección se consulta, cuando quien pregunta la nombra por tipo
     * (Spec 0092).
     *
     * `delTenant()` sin id devuelve la más reciente **del tenant**, sea de lo que
     * sea. Eso vale para el cruce, que se abre sobre «la elección que estoy
     * escrutando», pero no para una página que se abre eligiendo elección: una
     * campaña con alcaldía y concejo cargados recibiría las estadísticas de la
     * otra sin enterarse. Aquí el tipo acota, y el id —si viene— manda.
     *
     * Nunca se crea nada: consultar no es dar de alta.
     */
    public function delTipo(string $tipo, ?int $id = null): ElectoralEvent
    {
        if ($id !== null) {
            return $this->delTenant($id);
        }

        // Con `TenantScope`: una elección de otra campaña sencillamente no
        // existe desde aquí.
        $evento = ElectoralEvent::query()
            ->where('tipo', $tipo)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();

        if ($evento) {
            return $evento;
        }

        throw ValidationException::withMessages([
            'tipo' => 'Todavía no hay ninguna elección de ese tipo en esta campaña.',
        ]);
    }

    /**
     * El tipo de elección del cargo del tenant, o `null` si no mapea (Spec 0080).
     *
     * Se normaliza la caja porque el enum de `tenants` se escribió capitalizado
     * y no hay garantía de que un dato viejo lo respete; lo que NO se hace es
     * inferir por parecido: fuera de la tabla, no hay tipo.
     */
    public function tipoDelCargo(?string $tipoCargo): ?string
    {
        $clave = mb_strtolower(trim((string) $tipoCargo));

        return self::TIPO_POR_CARGO[$clave] ?? null;
    }

    /**
     * La elección a la que aplica el candidato del tenant (Spec 0080), sin crearla.
     *
     * Una campaña es un candidato a un cargo, así que su número de tarjetón vive
     * en **una** elección: la del `tipo_cargo`. Si hay varias del mismo tipo —dos
     * alcaldías de años distintos— gana la más reciente, que es la que se está
     * escrutando.
     *
     * El filtro por `tenant_id` va explícito y sin el scope: esto también corre
     * desde consola (la limpieza de la 0080), donde no hay tenant en el
     * contenedor y el `TenantScope` no filtraría nada.
     */
    public function delCargo(Tenant $tenant): ?ElectoralEvent
    {
        $tipo = $this->tipoDelCargo($tenant->tipo_cargo);

        if ($tipo === null) {
            return null;
        }

        return ElectoralEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('tipo', $tipo)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * La misma elección, creándola si aún no existe (Spec 0080).
     *
     * El número del tarjetón se sabe semanas antes de la primera acta, así que
     * fijar el candidato no puede exigir que la elección ya esté cargada: se da
     * de alta al vuelo, igual que hace la ingesta.
     */
    public function paraElCargo(Tenant $tenant): ElectoralEvent
    {
        $tipo = $this->tipoDelCargo($tenant->tipo_cargo);

        if ($tipo === null) {
            throw ValidationException::withMessages([
                'cargo' => 'La campaña no tiene un cargo de elección popular configurado '
                    .'(«'.($tenant->tipo_cargo ?: 'sin cargo').'»), así que no hay elección '
                    .'donde fijar el número. Configura el cargo de la campaña primero.',
            ]);
        }

        return $this->delCargo($tenant) ?? ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => $this->nombrePorDefecto($tipo),
            'fecha' => null,
        ]);
    }

    private function nombrePorDefecto(string $tipo): string
    {
        return match ($tipo) {
            'alcaldia' => 'Alcaldía',
            'gobernacion' => 'Gobernación',
            'concejo' => 'Concejo',
            'senado' => 'Senado',
            'asamblea_departamental' => 'Asamblea Departamental',
            default => ucfirst($tipo),
        };
    }
}

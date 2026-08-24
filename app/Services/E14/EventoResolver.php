<?php

namespace App\Services\E14;

use App\Models\E14Acta;
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
     * Qué elección escruta esta campaña, o 422 si no escruta (Spec 0093).
     *
     * Es el único sitio del que sale el tipo para el escrutinio: la carga, el
     * cruce, el consolidado, las estadísticas y la proyección lo piden aquí en
     * vez de leer un `?tipo=` de la URL. Un tenant sirve a **una** elección, así
     * que dejar que el cliente eligiera cuál era, de hecho, la forma de mirar
     * otra.
     *
     * Un cargo `Otro` no escruta y se dice: devolver la elección de al lado
     * —o un vacío mudo— dejaría a quien pregunta creyendo que no hay actas.
     */
    public function tipoDeLaCampana(Tenant $tenant): string
    {
        $tipo = $tenant->tipoEleccion();

        if ($tipo === null) {
            throw ValidationException::withMessages([
                'cargo' => 'La campaña no tiene un cargo de elección popular configurado '
                    .'(«'.($tenant->tipo_cargo ?: 'sin cargo').'»), así que no hay escrutinio '
                    .'que cargar ni consultar.',
            ]);
        }

        return $tipo;
    }

    /**
     * La elección de esta campaña, la única que sus pantallas pueden mirar
     * (Spec 0093).
     *
     * Con `id` manda el id, pero **acotado**: una elección de otro tipo se
     * rechaza en vez de servirse. Sin él se toma la más reciente del tipo del
     * tenant. Es la diferencia con `delTenant()`, que devolvía la más reciente
     * fuera cual fuera su tipo: una campaña de alcaldía con un evento de concejo
     * cargado por error acababa viendo el cruce del concejo sin enterarse.
     */
    public function deLaCampana(Tenant $tenant, ?int $id = null): ElectoralEvent
    {
        $tipo = $this->tipoDeLaCampana($tenant);

        $evento = $id !== null ? $this->delTenant($id) : $this->masRecienteDe($tipo);

        if ($evento->tipo !== $tipo) {
            throw ValidationException::withMessages([
                'event' => 'Esa elección es de '.E14Acta::nombreDe($evento->tipo)
                    .' y esta campaña escruta '.E14Acta::nombreDe($tipo).'.',
            ]);
        }

        return $evento;
    }

    /**
     * La más reciente de un tipo, o el aviso de que no hay ninguna (Spec 0092).
     *
     * `delTenant()` sin id devuelve la más reciente **del tenant**, sea de lo
     * que sea; eso valía cuando el cruce se abría sobre «la última elección»,
     * pero una campaña con dos cargadas recibía la que no era sin enterarse.
     * Aquí el tipo acota — y desde la 0093 ese tipo es siempre el del tenant.
     *
     * Nunca se crea nada: consultar no es dar de alta.
     */
    private function masRecienteDe(string $tipo): ElectoralEvent
    {
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
            'event' => 'Todavía no hay ninguna elección de '.E14Acta::nombreDe($tipo)
                .' cargada en esta campaña.',
        ]);
    }

    /**
     * El tipo de elección del cargo del tenant, o `null` si no mapea (Spec 0080).
     *
     * El mapa vive en `Tenant::ELECCION_POR_CARGO` desde la 0093, pegado al enum
     * de cargos que traduce: la 0093 lo convirtió en **la** elección del tenant
     * —la que manda en toda la app— y tenerlo aquí lo dejaba escondido en un
     * servicio del E-14. Esto se queda como el atajo que ya usan la 0080 y la
     * 0082, no como una segunda tabla.
     */
    public function tipoDelCargo(?string $tipoCargo): ?string
    {
        return Tenant::eleccionDelCargo($tipoCargo);
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
        return E14Acta::nombreDe($tipo);
    }
}

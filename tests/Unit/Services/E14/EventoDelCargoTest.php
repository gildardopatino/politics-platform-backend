<?php

namespace Tests\Unit\Services\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\E14\EventoResolver;
use Tests\TestCase;

/**
 * A qué elección aplica el candidato del tenant (Spec 0080).
 *
 * El tenant dice su cargo en `tipo_cargo`, pero ese campo **no habla el mismo
 * idioma** que `electoral_events.tipo`: el primero es un enum en capitalizado
 * (`Alcaldia`, `Diputado`…) heredado del alta de campañas, y el segundo el
 * vocabulario del E-14 (`alcaldia`, `asamblea_departamental`…). Traducir por
 * texto —minúsculas, sin tildes— acertaría en tres casos y fallaría en el resto,
 * así que la tabla es explícita y vive en un solo sitio.
 *
 * Lo que no mapea **no se adivina**: `Otro` no es un cargo de elección popular y
 * la Cámara todavía no tiene tipo de acta. En esos casos no hay elección, y el
 * endpoint avisa en vez de escribir el número en la elección equivocada.
 */
class EventoDelCargoTest extends TestCase
{
    private EventoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new EventoResolver;
    }

    private function tenant(?string $cargo): Tenant
    {
        return Tenant::factory()->create(['tipo_cargo' => $cargo]);
    }

    private function evento(Tenant $tenant, string $tipo, string $fecha, string $nombre = 'X'): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'fecha' => $fecha,
        ]);
    }

    // ------------------------------------------------------------- el mapeo

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cargosQueMapean(): array
    {
        return [
            'alcaldía' => ['Alcaldia', 'alcaldia'],
            'gobernación' => ['Gobernacion', 'gobernacion'],
            'concejo' => ['Concejo', 'concejo'],
            // Un diputado se elige en la asamblea departamental: el cargo y el
            // tipo de acta se llaman distinto, y es justo lo que la tabla salva.
            'diputado → asamblea' => ['Diputado', 'asamblea_departamental'],
            // El E-14 solo tiene `senado` para el Congreso. La Cámara ni siquiera
            // es un cargo elegible desde la 0084: sin actas no hay qué escrutar.
            'congresista → senado' => ['Congresista', 'senado'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cargosQueMapean')]
    public function test_cada_cargo_conocido_tiene_su_tipo_de_eleccion(string $cargo, string $tipo): void
    {
        $this->assertSame($tipo, $this->resolver->tipoDelCargo($cargo));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function cargosQueNoMapean(): array
    {
        return [
            'sin cargo' => [null],
            'vacío' => [''],
            'Otro' => ['Otro'],
            // Cámara de Representantes: dejó de ser un cargo elegible en la 0084
            // (sin actas de Cámara no hay escrutinio que ofrecer). Se queda en la
            // lista por si alguna campaña vieja lo tuviera a mano: sin elección,
            // el cruce avisa en vez de escribir en la equivocada.
            'Representante' => ['Representante'],
            'inventado' => ['Presidencia'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cargosQueNoMapean')]
    public function test_lo_que_no_esta_en_la_tabla_no_se_adivina(?string $cargo): void
    {
        $this->assertNull($this->resolver->tipoDelCargo($cargo));
    }

    public function test_el_mapeo_no_es_sensible_a_mayusculas_ni_a_espacios(): void
    {
        // El enum guarda `Alcaldia`, pero un dato viejo con otra caja no debería
        // dejar la campaña sin candidato.
        $this->assertSame('alcaldia', $this->resolver->tipoDelCargo('  alcaldia '));
        $this->assertSame('alcaldia', $this->resolver->tipoDelCargo('ALCALDIA'));
    }

    public function test_todo_tipo_que_devuelve_la_tabla_existe_en_el_vocabulario_del_e14(): void
    {
        // Si alguien añade una fila con un tipo que el E-14 no conoce, el
        // candidato se escribiría en una elección que ninguna acta va a poblar.
        // El mapa vive en `Tenant` desde la 0093, pegado al enum de cargos que
        // traduce; `tipoDelCargo()` es su atajo.
        foreach (\App\Models\Tenant::ELECCION_POR_CARGO as $cargo => $tipo) {
            if ($tipo === null) {
                continue;
            }

            $this->assertContains($tipo, \App\Models\E14Acta::TIPOS, "El cargo {$cargo} apunta a un tipo desconocido.");
        }
    }

    // ------------------------------------------------- resolver la elección

    public function test_sin_cargo_mapeable_no_hay_eleccion(): void
    {
        $tenant = $this->tenant('Otro');
        $this->evento($tenant, 'alcaldia', '2027-10-31');

        $this->assertNull($this->resolver->delCargo($tenant));
    }

    public function test_toma_la_eleccion_del_tipo_del_cargo_y_no_otra(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $this->evento($tenant, 'concejo', '2027-10-31', 'Concejo');
        $alcaldia = $this->evento($tenant, 'alcaldia', '2027-10-31', 'Alcaldía');

        $this->assertSame($alcaldia->id, $this->resolver->delCargo($tenant)?->id);
    }

    public function test_con_varias_elecciones_del_mismo_tipo_gana_la_mas_reciente(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $this->evento($tenant, 'alcaldia', '2023-10-29', 'Alcaldía 2023');
        $reciente = $this->evento($tenant, 'alcaldia', '2027-10-31', 'Alcaldía 2027');

        $this->assertSame($reciente->id, $this->resolver->delCargo($tenant)?->id);
    }

    public function test_no_ve_la_eleccion_de_otra_campana(): void
    {
        $ajeno = $this->tenant('Alcaldia');
        $this->evento($ajeno, 'alcaldia', '2027-10-31', 'Alcaldía ajena');

        $propio = $this->tenant('Alcaldia');

        $this->assertNull($this->resolver->delCargo($propio));
    }

    public function test_consultar_no_da_de_alta_la_eleccion(): void
    {
        $tenant = $this->tenant('Alcaldia');

        $this->assertNull($this->resolver->delCargo($tenant));
        $this->assertSame(0, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
    }

    // --------------------------------------------------- find-or-create

    public function test_fijar_el_candidato_crea_la_eleccion_del_cargo_si_no_existe(): void
    {
        $tenant = $this->tenant('Diputado');

        $evento = $this->resolver->paraElCargo($tenant);

        $this->assertSame('asamblea_departamental', $evento->tipo);
        $this->assertSame('Asamblea Departamental', $evento->nombre);
        $this->assertSame($tenant->id, $evento->tenant_id);
    }

    public function test_no_duplica_la_eleccion_si_ya_existe(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $existente = $this->evento($tenant, 'alcaldia', '2027-10-31', 'Alcaldía');

        $evento = $this->resolver->paraElCargo($tenant);

        $this->assertSame($existente->id, $evento->id);
        $this->assertSame(1, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_sin_cargo_mapeable_no_crea_nada_y_falla(): void
    {
        $tenant = $this->tenant('Otro');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            $this->resolver->paraElCargo($tenant);
        } finally {
            // Lo importante no es solo que falle: es que no deje una elección
            // inventada detrás.
            $this->assertSame(0, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
        }
    }
}

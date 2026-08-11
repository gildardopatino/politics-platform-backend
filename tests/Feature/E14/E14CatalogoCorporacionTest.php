<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\E14\CatalogoDeCorporacion;
use Tests\TestCase;

/**
 * De dónde salen las listas y los preferentes del selector (Spec 0082).
 *
 * En uninominal el catálogo es `e14_candidates`, que la ingesta llena sola con
 * la primera acta. En corporación no hay tabla equivalente —el acta no trae
 * nombres, así que no hay nada que catalogar (0067)— y el catálogo se **deriva**
 * de lo que las actas ya leyeron: `e14_lista_resultados` para las listas y
 * `e14_lista_preferentes` para sus números.
 *
 * Solo cuentan las actas **procesada**, igual que el consolidado. Una que no
 * cuadra pudo leer mal el número de la lista, y ofrecer un «517» que en realidad
 * era «5170» dejaría fijar el candidato en una lista que no existe.
 */
class E14CatalogoCorporacionTest extends TestCase
{
    private CatalogoDeCorporacion $catalogo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogo = new CatalogoDeCorporacion;
    }

    private function evento(Tenant $tenant, string $tipo = E14Acta::TIPO_CONCEJO): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => 'Concejo',
            'fecha' => '2027-10-31',
        ]);
    }

    private function acta(
        ElectoralEvent $evento,
        string $mesa = '001',
        string $estado = E14Acta::ESTADO_PROCESADA
    ): E14Acta {
        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $evento->tenant_id,
            'electoral_event_id' => $evento->id,
            'tipo' => $evento->tipo,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'estado' => $estado,
        ]);
    }

    /**
     * @param  array<int, int>  $preferentes
     */
    private function lista(E14Acta $acta, int $numero, ?string $nombre, array $preferentes): void
    {
        $lista = E14ListaResultado::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $acta->tenant_id,
            'e14_acta_id' => $acta->id,
            'lista_numero' => $numero,
            'lista_nombre' => $nombre,
        ]);

        foreach ($preferentes as $preferente) {
            E14ListaPreferente::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $acta->tenant_id,
                'e14_acta_id' => $acta->id,
                'e14_lista_resultado_id' => $lista->id,
                'numero' => $preferente,
                'votos' => 1,
            ]);
        }
    }

    // ------------------------------------------------------------ la forma

    public function test_sin_actas_el_catalogo_esta_vacio(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame([], $this->catalogo->paraEvento($this->evento($tenant)));
    }

    public function test_devuelve_cada_lista_con_sus_preferentes(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);
        $acta = $this->acta($evento);

        $this->lista($acta, 11, 'PARTIDO CENTRO DEMOCRÁTICO', [1, 5, 9]);
        $this->lista($acta, 1, 'PARTIDO LIBERAL COLOMBIANO', [3]);

        $catalogo = $this->catalogo->paraEvento($evento);

        // Ordenado por número de lista, como el tarjetón.
        $this->assertSame([1, 11], array_column($catalogo, 'lista_numero'));
        $this->assertSame('PARTIDO LIBERAL COLOMBIANO', $catalogo[0]['lista_nombre']);
        $this->assertSame([['numero' => 3]], $catalogo[0]['preferentes']);
        $this->assertSame(
            [['numero' => 1], ['numero' => 5], ['numero' => 9]],
            $catalogo[1]['preferentes'],
        );
    }

    public function test_una_lista_sin_preferentes_sigue_en_el_catalogo(): void
    {
        // «LISTA SIN VOTO PREFERENTE»: existe en el tarjetón y no tiene números.
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista($this->acta($evento), 37, 'FUERZA CIUDADANA', []);

        $catalogo = $this->catalogo->paraEvento($evento);

        $this->assertCount(1, $catalogo);
        $this->assertSame([], $catalogo[0]['preferentes']);
    }

    // ----------------------------------------------------- varias actas

    public function test_une_lo_que_leyeron_varias_mesas_sin_repetir(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        // Una mesa vio los preferentes 1 y 5; otra, el 1 y el 9.
        $this->lista($this->acta($evento, '001'), 11, 'CENTRO DEMOCRÁTICO', [1, 5]);
        $this->lista($this->acta($evento, '002'), 11, 'CENTRO DEMOCRÁTICO', [1, 9]);

        $catalogo = $this->catalogo->paraEvento($evento);

        $this->assertCount(1, $catalogo, 'la lista es una sola aunque esté en dos actas');
        $this->assertSame(
            [['numero' => 1], ['numero' => 5], ['numero' => 9]],
            $catalogo[0]['preferentes'],
        );
    }

    public function test_con_dos_grafias_del_partido_se_queda_con_una(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista($this->acta($evento, '001'), 11, 'PARTIDO CENTRO DEMOCRATICO', [1]);
        $this->lista($this->acta($evento, '002'), 11, null, [1]);

        $catalogo = $this->catalogo->paraEvento($evento);

        // El que manda es el número; el nombre es para que se lea.
        $this->assertCount(1, $catalogo);
        $this->assertSame('PARTIDO CENTRO DEMOCRATICO', $catalogo[0]['lista_nombre']);
    }

    // ------------------------------------------------ solo las que cuadran

    public function test_un_acta_que_no_cuadra_no_aporta_al_catalogo(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista($this->acta($evento, '001'), 11, 'CENTRO DEMOCRÁTICO', [1]);
        // Pudo leer mal el número: un «517» que era «5170» dejaría fijar el
        // candidato en una lista que no existe.
        $this->lista(
            $this->acta($evento, '002', E14Acta::ESTADO_INCONSISTENTE),
            517,
            'LISTA MAL LEÍDA',
            [3]
        );

        $catalogo = $this->catalogo->paraEvento($evento);

        $this->assertSame([11], array_column($catalogo, 'lista_numero'));
    }

    public function test_un_acta_en_revision_tampoco(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista(
            $this->acta($evento, '001', E14Acta::ESTADO_REVISION_MANUAL),
            11,
            'CENTRO DEMOCRÁTICO',
            [1]
        );

        $this->assertSame([], $this->catalogo->paraEvento($evento));
    }

    // --------------------------------------------------------- aislamiento

    public function test_no_mezcla_las_listas_de_otra_eleccion(): void
    {
        $tenant = Tenant::factory()->create();
        $concejo = $this->evento($tenant);
        $asamblea = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => E14Acta::TIPO_ASAMBLEA,
            'nombre' => 'Asamblea',
        ]);

        $this->lista($this->acta($concejo), 11, 'CONCEJO', [1]);
        $this->lista($this->acta($asamblea), 99, 'ASAMBLEA', [2]);

        $this->assertSame([11], array_column($this->catalogo->paraEvento($concejo), 'lista_numero'));
        $this->assertSame([99], array_column($this->catalogo->paraEvento($asamblea), 'lista_numero'));
    }

    public function test_no_mezcla_las_listas_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $eventoAjeno = $this->evento($ajeno);
        $this->lista($this->acta($eventoAjeno), 99, 'LISTA AJENA', [7]);

        $propio = Tenant::factory()->create();
        $eventoPropio = $this->evento($propio);
        $this->lista($this->acta($eventoPropio), 11, 'LISTA PROPIA', [1]);

        // El catálogo va por evento, y una elección es de un solo tenant.
        $this->assertSame(
            [11],
            array_column($this->catalogo->paraEvento($eventoPropio), 'lista_numero'),
        );
    }

    // ------------------------------------------------------ la comprobación

    public function test_sabe_si_un_par_lista_preferente_existe(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista($this->acta($evento), 11, 'CENTRO DEMOCRÁTICO', [1, 5]);

        $catalogo = $this->catalogo->paraEvento($evento);

        $this->assertTrue($this->catalogo->tieneLista($catalogo, 11));
        $this->assertFalse($this->catalogo->tieneLista($catalogo, 24));

        $this->assertTrue($this->catalogo->tienePreferente($catalogo, 11, 5));
        $this->assertFalse($this->catalogo->tienePreferente($catalogo, 11, 9));
        $this->assertFalse($this->catalogo->tienePreferente($catalogo, 24, 5));
    }

    public function test_el_nombre_de_una_lista_del_catalogo(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        $this->lista($this->acta($evento), 11, 'PARTIDO CENTRO DEMOCRÁTICO', [1]);

        $catalogo = $this->catalogo->paraEvento($evento);

        $this->assertSame(
            'PARTIDO CENTRO DEMOCRÁTICO',
            $this->catalogo->nombreDeLista($catalogo, 11),
        );
        $this->assertNull($this->catalogo->nombreDeLista($catalogo, 24));
    }
}

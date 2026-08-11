<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * El resultado de corporación, en dos niveles (Spec 0067 · Parte B · RF-B1).
 *
 * Un acta de concejo no tiene candidatos: tiene **agrupaciones**, y dentro de
 * cada una los votos por la lista y por cada preferente. Lo que se fija aquí es
 * la forma de la tabla y sus dos invariantes: una agrupación no puede aparecer
 * dos veces en la misma acta, ni un preferente dos veces en la misma lista.
 *
 * Y lo que **no** cambió: `e14_resultados` sigue igual, con su único
 * `(acta, numero)` intacto. Meter los preferentes ahí habría obligado a ampliar
 * ese índice con una columna nula, que en PostgreSQL deja de proteger las filas
 * uninominales.
 */
class E14ModeloCorporacionTest extends TestCase
{
    private function acta(Tenant $tenant, string $tipo = E14Acta::TIPO_CONCEJO): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => 'Concejo',
            'fecha' => '2027-10-31',
        ]);

        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => $tipo,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
        ]);
    }

    private function lista(E14Acta $acta, int $numero, string $nombre = 'PARTIDO X'): E14ListaResultado
    {
        return E14ListaResultado::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $acta->tenant_id,
            'e14_acta_id' => $acta->id,
            'lista_numero' => $numero,
            'lista_nombre' => $nombre,
            'votos_solo_lista' => 2,
            'total_agrupacion' => 8,
        ]);
    }

    private function preferente(E14ListaResultado $lista, int $numero, int $votos): E14ListaPreferente
    {
        return E14ListaPreferente::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $lista->tenant_id,
            'e14_acta_id' => $lista->e14_acta_id,
            'e14_lista_resultado_id' => $lista->id,
            'numero' => $numero,
            'votos' => $votos,
        ]);
    }

    // ------------------------------------------------------------ el tipo

    public function test_solo_concejo_senado_y_asamblea_son_corporacion(): void
    {
        $this->assertTrue(E14Acta::esCorporacion(E14Acta::TIPO_CONCEJO));
        $this->assertTrue(E14Acta::esCorporacion(E14Acta::TIPO_SENADO));
        $this->assertTrue(E14Acta::esCorporacion(E14Acta::TIPO_ASAMBLEA));

        $this->assertFalse(E14Acta::esCorporacion(E14Acta::TIPO_ALCALDIA));
        $this->assertFalse(E14Acta::esCorporacion(E14Acta::TIPO_GOBERNACION));
        $this->assertFalse(E14Acta::esCorporacion(null));
    }

    public function test_uninominales_y_corporaciones_cubren_todos_los_tipos(): void
    {
        // Si alguien añade un tipo y no lo clasifica, el cuadre no sabría por
        // qué rama mandarlo.
        $clasificados = [...E14Acta::TIPOS_UNINOMINALES, ...E14Acta::TIPOS_CORPORACION];

        sort($clasificados);
        $todos = E14Acta::TIPOS;
        sort($todos);

        $this->assertSame($todos, $clasificados);
    }

    // ---------------------------------------------------------- los niveles

    public function test_una_lista_guarda_sus_preferentes(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);
        $lista = $this->lista($acta, 11, 'PARTIDO CENTRO DEMOCRÁTICO');

        $this->preferente($lista, 19, 2);
        $this->preferente($lista, 1, 6);

        $lista->load('preferentes');

        // Ordenados por número, no por inserción: es como se lee el papel.
        $this->assertSame([1, 19], $lista->preferentes->pluck('numero')->all());
        $this->assertSame(10, $lista->sumaCalculada()); // 2 de lista + 6 + 2
    }

    public function test_una_lista_sin_voto_preferente_no_tiene_candidatos(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);

        $lista = E14ListaResultado::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'e14_acta_id' => $acta->id,
            'lista_numero' => 37,
            'lista_nombre' => 'MOVIMIENTO POLITICO FUERZA CIUDADANA',
            'votos_solo_lista' => 1,
            'total_agrupacion' => 1,
            'con_voto_preferente' => false,
        ]);

        $this->assertFalse($lista->con_voto_preferente);
        $this->assertCount(0, $lista->preferentes);
        $this->assertSame(1, $lista->sumaCalculada());
    }

    public function test_la_etiqueta_nombra_numero_y_partido(): void
    {
        $tenant = Tenant::factory()->create();
        $lista = $this->lista($this->acta($tenant), 11, 'PARTIDO CENTRO DEMOCRÁTICO');

        $this->assertSame('11 · PARTIDO CENTRO DEMOCRÁTICO', $lista->etiqueta());
    }

    public function test_el_acta_ve_sus_listas_y_todos_sus_preferentes(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);

        $this->preferente($this->lista($acta, 1, 'LIBERAL'), 3, 1);
        $this->preferente($this->lista($acta, 2, 'CONSERVADOR'), 3, 4);

        $acta->load('listas.preferentes', 'preferentes');

        $this->assertCount(2, $acta->listas);
        // El mismo número de preferencia en dos listas son dos personas.
        $this->assertCount(2, $acta->preferentes);
    }

    // ------------------------------------------------------- los invariantes

    public function test_una_agrupacion_no_puede_repetirse_en_la_misma_acta(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);
        $this->lista($acta, 11);

        $this->expectException(QueryException::class);
        $this->lista($acta, 11);
    }

    public function test_la_misma_lista_puede_estar_en_dos_actas(): void
    {
        $tenant = Tenant::factory()->create();
        $uno = $this->acta($tenant);
        $otro = E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $uno->electoral_event_id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '002',
        ]);

        $this->lista($uno, 11);
        $this->lista($otro, 11);

        $this->assertSame(2, E14ListaResultado::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_un_preferente_no_puede_repetirse_en_la_misma_lista(): void
    {
        $tenant = Tenant::factory()->create();
        $lista = $this->lista($this->acta($tenant), 11);
        $this->preferente($lista, 5, 1);

        $this->expectException(QueryException::class);
        $this->preferente($lista, 5, 2);
    }

    public function test_el_mismo_preferente_puede_estar_en_dos_listas(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);

        // El 5 del liberal y el 5 del conservador son dos personas distintas:
        // esto es exactamente lo que `e14_resultados` no podía representar.
        $this->preferente($this->lista($acta, 1, 'LIBERAL'), 5, 3);
        $this->preferente($this->lista($acta, 2, 'CONSERVADOR'), 5, 7);

        $this->assertSame(2, E14ListaPreferente::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_borrar_el_acta_arrastra_listas_y_preferentes(): void
    {
        $tenant = Tenant::factory()->create();
        $acta = $this->acta($tenant);
        $this->preferente($this->lista($acta, 11), 1, 6);

        $acta->delete();

        $this->assertSame(0, E14ListaResultado::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, E14ListaPreferente::withoutGlobalScope(TenantScope::class)->count());
    }

    // ------------------------------------------------------- multi-tenant

    public function test_un_tenant_no_ve_las_listas_de_otro(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->lista($this->acta($ajeno), 11, 'LISTA AJENA');

        $propio = Tenant::factory()->create();
        $this->lista($this->acta($propio), 11, 'LISTA PROPIA');

        [$user, $token] = $this->createTenantWithUser([], $propio);
        $this->actingAsTenantUser($user, $token);
        app()->instance('current_tenant_id', $propio->id);

        $this->assertSame(
            ['LISTA PROPIA'],
            E14ListaResultado::query()->pluck('lista_nombre')->all(),
        );
    }
}

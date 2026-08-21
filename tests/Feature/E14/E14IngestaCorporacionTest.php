<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La ingesta de un acta de corporación (Spec 0067 · Parte B · RF-B2).
 *
 * El lector (0067-A) publica el resultado **anidado**: `listas[]`, y dentro de
 * cada una sus `preferentes[]`. Si el backend no lo acepta, un acta de concejo
 * se guardaría en blanco — con la forma correcta y sin un solo voto.
 *
 * Los números son los del acta real que 0067-A transcribió del papel
 * (`actas/concejo/zona_1_mesa001.pdf`), recortada a cuatro agrupaciones para que
 * la prueba se lea: 3 + 8 + 1 + 20 = 32 de listas, + 8 + 3 + 12 de controles =
 * 55 en la urna.
 *
 * Y lo que **no** cambia: el uninominal entra por el mismo endpoint y sigue
 * guardándose en `e14_resultados`, con su `suma_declarada` y su cuadre de
 * siempre.
 */
class E14IngestaCorporacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * Las cuatro agrupaciones de la muestra. Suman 32.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listas(): array
    {
        return [
            [
                'lista_numero' => 29,
                'lista_nombre' => 'NUEVA FUERZA DEMOCRÁTICA',
                'votos_solo_lista' => 0,
                'total_agrupacion' => 3,
                'con_voto_preferente' => true,
                // Los números NO son correlativos: el acta salta los renglones
                // que la lista no llenó.
                'preferentes' => [
                    ['numero' => 6, 'votos' => 1],
                    ['numero' => 10, 'votos' => 1],
                    ['numero' => 18, 'votos' => 1],
                ],
            ],
            [
                'lista_numero' => 1,
                'lista_nombre' => 'PARTIDO LIBERAL COLOMBIANO',
                'votos_solo_lista' => 2,
                'total_agrupacion' => 8,
                'con_voto_preferente' => true,
                'preferentes' => [
                    ['numero' => 3, 'votos' => 1],
                    ['numero' => 7, 'votos' => 1],
                    ['numero' => 10, 'votos' => 2],
                    ['numero' => 12, 'votos' => 1],
                    ['numero' => 19, 'votos' => 1],
                ],
            ],
            [
                // «LISTA SIN VOTO PREFERENTE»: un solo renglón, sin candidatos.
                'lista_numero' => 37,
                'lista_nombre' => 'MOVIMIENTO POLITICO FUERZA CIUDADANA',
                'votos_solo_lista' => 1,
                'total_agrupacion' => 1,
                'con_voto_preferente' => false,
                'preferentes' => [],
            ],
            [
                'lista_numero' => 11,
                'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                'votos_solo_lista' => 5,
                'total_agrupacion' => 20,
                'con_voto_preferente' => true,
                'preferentes' => [
                    ['numero' => 1, 'votos' => 6],
                    ['numero' => 5, 'votos' => 1],
                    ['numero' => 9, 'votos' => 1],
                    ['numero' => 11, 'votos' => 2],
                    ['numero' => 12, 'votos' => 1],
                    ['numero' => 16, 'votos' => 1],
                    ['numero' => 18, 'votos' => 1],
                    ['numero' => 19, 'votos' => 2],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function acta(array $cambios = []): array
    {
        return array_replace([
            'tipo' => E14Acta::TIPO_CONCEJO,
            'estado' => 'procesada',
            'departamento_code' => '29',
            'municipio_code' => '001',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'lugar' => 'UNIVERSIDAD COOPERATIVA NUEVA SEDE',
            'listas' => $this->listas(),
            'votos_blanco' => 8,
            'votos_nulos' => 3,
            'votos_no_marcados' => 12,
            // Sin `suma_declarada`: esa casilla no existe en el papel.
            'votos_urna' => 55,
            'votantes_e11' => 55,
        ], $cambios);
    }

    // --------------------------------------------- ingesta directa (0061)

    public function test_persiste_las_listas_y_sus_preferentes(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'procesada');

        $acta = E14Acta::first();

        $this->assertSame(4, $acta->listas()->count());
        $this->assertSame(16, $acta->preferentes()->count());

        // El uninominal no se toca: un acta de corporación no deja filas ahí.
        $this->assertSame(0, E14Resultado::count());
    }

    public function test_cada_lista_guarda_sus_dos_niveles(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $lista = E14ListaResultado::where('lista_numero', 11)->first();

        $this->assertSame('PARTIDO CENTRO DEMOCRÁTICO', $lista->lista_nombre);
        $this->assertSame(5, $lista->votos_solo_lista);
        $this->assertSame(20, $lista->total_agrupacion);
        $this->assertTrue($lista->con_voto_preferente);
        $this->assertSame(20, $lista->sumaCalculada());
        $this->assertSame(
            [1 => 6, 5 => 1, 9 => 1, 11 => 2, 12 => 1, 16 => 1, 18 => 1, 19 => 2],
            $lista->preferentes->pluck('votos', 'numero')->all(),
        );
    }

    public function test_una_lista_sin_voto_preferente_se_guarda_sin_candidatos(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $lista = E14ListaResultado::where('lista_numero', 37)->first();

        $this->assertFalse($lista->con_voto_preferente);
        $this->assertSame(0, $lista->preferentes()->count());
        $this->assertSame(1, $lista->votos_solo_lista);
        $this->assertSame(1, $lista->total_agrupacion);
    }

    public function test_el_mismo_preferente_en_dos_listas_son_dos_personas(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        // El 10 aparece en la 29 (1 voto) y en la liberal (2). Si se fundieran,
        // uno de los dos perdería sus votos.
        $decimos = E14ListaPreferente::where('numero', 10)->get();

        $this->assertCount(2, $decimos);
        $this->assertEqualsCanonicalizing([1, 2], $decimos->pluck('votos')->all());
    }

    public function test_el_acta_cuadra_contra_la_urna_sin_suma_declarada(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $acta = E14Acta::first();

        $this->assertSame(E14Acta::ESTADO_PROCESADA, $acta->estado);
        $this->assertSame(55, $acta->suma_calculada);
        $this->assertSame(55, $acta->votos_urna);
        // La casilla no existe en corporación: se guarda en cero, no se exige.
        $this->assertSame(0, $acta->suma_declarada);
        $this->assertNull($acta->observacion);
    }

    public function test_no_hace_falta_mandar_suma_declarada(): void
    {
        $this->operador();

        $payload = $this->acta();
        $this->assertArrayNotHasKey('suma_declarada', $payload);

        $this->postJson('/api/v1/e14/actas', $payload)->assertStatus(201);
    }

    // ------------------------------------------------------- el self-check

    public function test_una_lista_que_no_cuadra_manda_el_acta_a_revision_nombrandola(): void
    {
        $this->operador();

        $listas = $this->listas();
        // El voto fantasma que la visión puso de verdad en la primera acta real.
        $listas[1]['preferentes'][] = ['numero' => 2, 'votos' => 1];

        $this->postJson('/api/v1/e14/actas', $this->acta(['listas' => $listas]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'inconsistente');

        $acta = E14Acta::first();

        $this->assertStringContainsString('1 · PARTIDO LIBERAL COLOMBIANO', $acta->observacion);
        $this->assertStringContainsString('8', $acta->observacion);
        $this->assertStringContainsString('9', $acta->observacion);

        // Aun sin cuadrar, los votos se guardan: quien revise necesita verlos.
        $this->assertSame(4, $acta->listas()->count());
    }

    public function test_el_global_se_contrasta_contra_la_urna(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta(['votos_urna' => 54]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'inconsistente');

        $this->assertStringContainsString('urna', E14Acta::first()->observacion);
    }

    public function test_un_acta_de_corporacion_sin_datos_va_a_revision(): void
    {
        $this->operador();

        $vacias = array_map(
            fn (array $lista) => [...$lista, 'votos_solo_lista' => 0, 'total_agrupacion' => 0, 'preferentes' => []],
            $this->listas(),
        );

        $this->postJson('/api/v1/e14/actas', $this->acta([
            'listas' => $vacias,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'votos_urna' => 0,
            'votantes_e11' => 0,
        ]))->assertStatus(201)->assertJsonPath('data.estado', 'revision_manual');
    }

    // --------------------------------- la nivelación de la mesa (Spec 0088)

    public function test_el_global_se_contrasta_contra_la_urna_nivelada(): void
    {
        $this->operador();

        // La misma acta (listas y controles suman 55), pero la urna trae 56 y un
        // voto incinerado para nivelar la mesa: se contaron 55.
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'votos_urna' => 56,
            'votos_incinerados' => 1,
            'votantes_e11' => 55,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.suma_calculada', 55)
            ->assertJsonPath('data.votos_urna', 56)
            ->assertJsonPath('data.votos_incinerados', 1)
            ->assertJsonPath('data.dif_nivelacion', 0);
    }

    public function test_sin_descontar_los_incinerados_la_misma_acta_no_cuadraria(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta(['votos_urna' => 56]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'inconsistente');
    }

    // ------------------------------------------------------- idempotencia

    public function test_reenviar_la_misma_acta_no_duplica_nada(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(200);

        $this->assertSame(1, E14Acta::count());
        $this->assertSame(4, E14ListaResultado::count());
        $this->assertSame(16, E14ListaPreferente::count());
    }

    public function test_una_relectura_con_menos_listas_no_deja_votos_huerfanos(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        // Segunda lectura: solo dos agrupaciones (3 + 8 = 11, + 23 = 34).
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'listas' => array_slice($this->listas(), 0, 2),
            'votos_urna' => 34,
            'votantes_e11' => 34,
        ]))->assertStatus(200)->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(2, E14ListaResultado::count());
        // Y sus preferentes se van con ellas: si no, seguirían sumando.
        $this->assertSame(8, E14ListaPreferente::count());
    }

    public function test_una_relectura_con_menos_preferentes_los_quita(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $listas = $this->listas();
        array_pop($listas[3]['preferentes']);          // fuera el 19 (2 votos)
        $listas[3]['total_agrupacion'] = 18;

        $this->postJson('/api/v1/e14/actas', $this->acta([
            'listas' => $listas,
            'votos_urna' => 53,
            'votantes_e11' => 53,
        ]))->assertStatus(200)->assertJsonPath('data.estado', 'procesada');

        $lista = E14ListaResultado::where('lista_numero', 11)->first();

        $this->assertSame(7, $lista->preferentes()->count());
        $this->assertNull($lista->preferentes()->where('numero', 19)->first());
    }

    // ----------------------------------------------------------- el detalle

    public function test_el_detalle_del_acta_trae_las_listas_anidadas(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $acta = E14Acta::first();

        $this->getJson("/api/v1/e14/actas/{$acta->id}")
            ->assertStatus(200)
            ->assertJsonCount(4, 'data.listas')
            ->assertJsonPath('data.listas.0.lista_numero', 29)
            ->assertJsonPath('data.listas.0.lista_nombre', 'NUEVA FUERZA DEMOCRÁTICA')
            ->assertJsonPath('data.listas.0.total_agrupacion', 3)
            // El calculado viaja para que el panel señale la lista que no cuadra
            // sin rehacer la cuenta.
            ->assertJsonPath('data.listas.0.suma_calculada', 3)
            ->assertJsonCount(3, 'data.listas.0.preferentes')
            ->assertJsonPath('data.listas.0.preferentes.0.numero', 6)
            ->assertJsonPath('data.listas.0.preferentes.0.votos', 1)
            // Un acta de corporación no tiene candidatos sueltos.
            ->assertJsonPath('data.resultados', []);
    }

    public function test_el_detalle_de_un_uninominal_no_trae_listas(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'estado' => 'procesada',
            'zona' => '01', 'puesto' => '01', 'mesa' => '001',
            'suma_declarada' => 12, 'votos_urna' => 12, 'votantes_e11' => 12,
            'resultados' => [['numero' => 1, 'nombre' => 'X', 'votos' => 12]],
        ])->assertStatus(201);

        $this->getJson('/api/v1/e14/actas/'.E14Acta::first()->id)
            ->assertStatus(200)
            ->assertJsonPath('data.listas', [])
            ->assertJsonCount(1, 'data.resultados');
    }

    // ------------------------------------------------------- worker (0071)

    public function test_el_worker_publica_el_resultado_anidado(): void
    {
        $tenant = $this->operador();

        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'nombre' => 'Concejo',
        ]);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'archivo_nombre' => 'acta.pdf',
            'archivo_hash' => hash('sha256', 'concejo'),
            'estado' => E14Acta::ESTADO_PROCESANDO,
        ]);

        $payload = $this->acta();
        unset($payload['tipo']);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        $acta->refresh();

        $this->assertSame('001', $acta->mesa);
        $this->assertSame(4, $acta->listas()->count());
        $this->assertSame(16, $acta->preferentes()->count());
        $this->assertSame(55, $acta->suma_calculada);
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_registrar_exige_manage_e14(): void
    {
        $this->operador([Permissions::VIEW_E14]);

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(403);
    }

    public function test_las_listas_de_otra_campana_no_se_ven(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $actaAjena = E14Acta::withoutGlobalScope(TenantScope::class)->firstOrFail();

        // Otra campaña, con su propia acta de la misma mesa.
        $this->operador();
        $this->postJson('/api/v1/e14/actas', $this->acta(['mesa' => '002']))->assertStatus(201);

        // El listado solo trae la suya…
        $this->getJson('/api/v1/e14/actas')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mesa', '002');

        // …y el acta del vecino no existe desde aquí, con listas y todo.
        $this->getJson("/api/v1/e14/actas/{$actaAjena->id}")->assertStatus(404);

        $this->assertSame(
            8,
            E14ListaResultado::withoutGlobalScope(TenantScope::class)->count(),
            'las dos campañas guardaron sus cuatro listas cada una',
        );
    }

    // ------------------------------------------------- el uninominal intacto

    public function test_un_acta_de_alcaldia_sigue_entrando_por_resultados(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ])->assertStatus(201)->assertJsonPath('data.estado', 'procesada');

        $acta = E14Acta::first();

        $this->assertSame(3, $acta->resultados()->count());
        $this->assertSame(111, $acta->suma_declarada);
        // Y ninguna fila de corporación.
        $this->assertSame(0, E14ListaResultado::count());
    }

    public function test_un_uninominal_sigue_exigiendo_resultados_y_suma_declarada(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'zona' => '01', 'puesto' => '01', 'mesa' => '001',
            'votos_urna' => 111,
        ])->assertStatus(422)->assertJsonValidationErrors(['resultados', 'suma_declarada']);
    }

    public function test_una_corporacion_legible_tiene_que_traer_listas(): void
    {
        $this->operador();

        $payload = $this->acta();
        unset($payload['listas']);

        $this->postJson('/api/v1/e14/actas', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('listas');
    }

    public function test_una_agrupacion_no_puede_venir_dos_veces(): void
    {
        $this->operador();

        $listas = $this->listas();
        $listas[] = $listas[0];

        $this->postJson('/api/v1/e14/actas', $this->acta(['listas' => $listas]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('listas.4.lista_numero');
    }
}

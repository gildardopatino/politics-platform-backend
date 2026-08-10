<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14Candidate;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Ingesta y consulta del escrutinio (Spec 0061 · Parte A).
 *
 * El caso que gobierna el diseño es el de la noche de escrutinio: el lector
 * corre varias veces sobre la misma carpeta, con cortes de red en medio, y lo
 * que no puede pasar es que una mesa se cuente dos veces ni que un acta que no
 * cuadra entre al total.
 */
class E14IngestTest extends TestCase
{
    /**
     * Acta que cuadra: 50 + 30 + 19 a candidatos, + 4 + 4 + 4 de controles = 111.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function acta(array $cambios = []): array
    {
        return array_replace([
            'tipo' => 'alcaldia',
            'archivo_nombre' => 'zona_1_mesa001.pdf',
            'archivo_hash' => str_repeat('a', 64),
            'fuente' => 'vision',
            'departamento_code' => '73',
            'municipio_code' => '73001',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'lugar' => 'INSTITUCION EDUCATIVA',
            'estado' => 'procesada',
            'suma_calculada' => 111,
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'dif_nivelacion' => 0,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'observacion' => '',
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ], $cambios);
    }

    /**
     * @param  array<int, string>  $permisos
     * @return array{0: User, 1: Tenant}
     */
    private function operador(array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14], ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return [$user, $tenant];
    }

    // ---------------------------------------------------------------- ingesta

    public function test_un_acta_que_cuadra_se_registra_como_procesada(): void
    {
        $this->operador();

        $respuesta = $this->postJson('/api/v1/e14/actas', $this->acta());

        $respuesta->assertStatus(201)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.suma_calculada', 111)
            ->assertJsonPath('data.dif_nivelacion', 0);

        $this->assertDatabaseHas('e14_actas', ['mesa' => '001', 'estado' => 'procesada']);
        $this->assertSame(3, E14Resultado::count());
    }

    public function test_el_servidor_recalcula_el_cuadre_y_no_cree_al_cliente(): void
    {
        $this->operador();

        // El cliente jura que cuadra, pero sus casillas suman 121 contra 111
        // declarados. Gana la aritmética.
        $respuesta = $this->postJson('/api/v1/e14/actas', $this->acta([
            'estado' => 'procesada',
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]));

        $respuesta->assertStatus(201)
            ->assertJsonPath('data.estado', 'inconsistente')
            ->assertJsonPath('data.suma_calculada', 121);

        $this->assertStringContainsString('121', $respuesta->json('data.observacion'));
        $this->assertStringContainsString('111', $respuesta->json('data.observacion'));
    }

    public function test_una_urna_que_no_coincide_tambien_deja_el_acta_fuera(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta(['votos_urna' => 110]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'inconsistente');
    }

    public function test_la_nivelacion_es_una_novedad_y_no_bloquea(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta(['votantes_e11' => 113]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.dif_nivelacion', 2);
    }

    public function test_un_acta_ilegible_se_registra_en_la_cola_de_revision(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => 'alcaldia',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '009',
            'estado' => 'revision_manual',
            'observacion' => 'la casilla del candidato 3 está tachada',
        ])->assertStatus(201)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.observacion', 'la casilla del candidato 3 está tachada');
    }

    public function test_reenviar_la_misma_mesa_actualiza_en_vez_de_duplicar(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        // Segunda pasada del lector: mismo archivo, una casilla releída.
        $segunda = $this->postJson('/api/v1/e14/actas', $this->acta([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 51],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 29],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]));

        $segunda->assertStatus(200)->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(1, E14Acta::count(), 'La misma mesa no puede quedar dos veces.');
        $this->assertSame(3, E14Resultado::count());
        $this->assertSame(51, E14Resultado::where('numero', 1)->first()->votos);
    }

    public function test_una_relectura_con_menos_candidatos_no_deja_votos_huerfanos(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $this->postJson('/api/v1/e14/actas', $this->acta([
            'suma_declarada' => 92,
            'votos_urna' => 92,
            'votantes_e11' => 92,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
            ],
        ]))->assertStatus(200)->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(2, E14Resultado::count(), 'El candidato que ya no aparece no puede seguir sumando.');
    }

    public function test_el_mismo_archivo_no_puede_radicarse_en_dos_mesas(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $this->postJson('/api/v1/e14/actas', $this->acta(['mesa' => '002']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo_hash');

        $this->assertSame(1, E14Acta::count());
    }

    public function test_el_evento_se_resuelve_solo_y_no_se_multiplica(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'mesa' => '002',
            'archivo_hash' => str_repeat('b', 64),
        ]))->assertStatus(201);

        $this->assertSame(1, ElectoralEvent::count());
    }

    public function test_el_catalogo_de_candidatos_se_llena_con_la_primera_acta(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $this->assertSame(3, E14Candidate::count());
        $this->assertDatabaseHas('e14_candidates', ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES']);
    }

    public function test_una_eleccion_de_otro_tenant_no_se_puede_indicar(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $otro->id,
            'nombre' => 'Alcaldia',
            'tipo' => 'alcaldia',
        ]);

        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta(['electoral_event_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('electoral_event_id');
    }

    // ------------------------------------------------------------- consulta

    public function test_el_consolidado_solo_suma_las_actas_que_cuadran(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        // Mesa 002: no cuadra (las casillas suman 121 y declara 111). Sus votos
        // no pueden aparecer en el total.
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'mesa' => '002',
            'archivo_hash' => str_repeat('b', 64),
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]))->assertStatus(201);

        $respuesta = $this->getJson('/api/v1/e14/consolidado')->assertStatus(200);

        $this->assertSame(
            [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'agrupacion' => null, 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'agrupacion' => null, 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'agrupacion' => null, 'votos' => 19],
            ],
            $respuesta->json('data')
        );

        $respuesta->assertJsonPath('meta.total_candidatos', 99)
            ->assertJsonPath('meta.total_votos', 111)
            ->assertJsonPath('meta.actas.procesada', 1)
            ->assertJsonPath('meta.actas.inconsistente', 1);
    }

    public function test_el_consolidado_se_abre_por_puesto_y_por_zona(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'zona' => '02',
            'mesa' => '001',
            'puesto' => '05',
            'archivo_hash' => str_repeat('c', 64),
        ]))->assertStatus(201);

        $respuesta = $this->getJson('/api/v1/e14/consolidado')->assertStatus(200);

        $this->assertCount(2, $respuesta->json('desglose.por_zona'));
        $this->assertCount(2, $respuesta->json('desglose.por_puesto'));
        $this->assertSame(99, $respuesta->json('desglose.por_zona.0.total'));
    }

    public function test_la_cola_de_revision_se_consulta_por_estado(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'mesa' => '003',
            'archivo_hash' => str_repeat('d', 64),
            'votos_urna' => 110,
        ]))->assertStatus(201);

        $this->getJson('/api/v1/e14/actas?estado=inconsistente')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mesa', '003');

        $this->getJson('/api/v1/e14/actas?zona=01')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_el_detalle_trae_los_votos_por_candidato(): void
    {
        $this->operador();

        $id = $this->postJson('/api/v1/e14/actas', $this->acta())->json('data.id');

        $this->getJson("/api/v1/e14/actas/{$id}")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.resultados')
            ->assertJsonPath('data.resultados.0.votos', 50);
    }

    // ----------------------------------------------------- corrección manual

    public function test_la_correccion_manual_vuelve_a_evaluar_el_cuadre(): void
    {
        $this->operador();

        // Entra sin cuadrar: 121 leídos contra 111 declarados.
        $id = $this->postJson('/api/v1/e14/actas', $this->acta([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]))->json('data.id');

        // Alguien mira el papel: el 1 tenía 50, no 60.
        $this->putJson("/api/v1/e14/actas/{$id}", [
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.fuente', 'manual')
            ->assertJsonPath('data.observacion', null);
    }

    public function test_corregir_no_es_aprobar(): void
    {
        $this->operador();

        $id = $this->postJson('/api/v1/e14/actas', $this->acta())->json('data.id');

        // Una corrección que rompe el cuadre no puede dejar el acta procesada.
        $this->putJson("/api/v1/e14/actas/{$id}", ['votos_urna' => 105])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'inconsistente')
            ->assertJsonPath('data.fuente', 'manual');
    }

    // ------------------------------------------------------------- permisos

    public function test_sin_permiso_de_lectura_no_se_consulta(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson('/api/v1/e14/actas')->assertStatus(403);
        $this->getJson('/api/v1/e14/consolidado')->assertStatus(403);
    }

    public function test_sin_permiso_de_escritura_no_se_publica(): void
    {
        $this->operador([Permissions::VIEW_E14]);

        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(403);

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    // --------------------------------------------------------------- tenant

    public function test_un_acta_de_otro_tenant_no_se_ve_ni_se_edita(): void
    {
        $otro = Tenant::factory()->create();
        $this->operador([Permissions::VIEW_E14, Permissions::MANAGE_E14], $otro);
        $ajena = $this->postJson('/api/v1/e14/actas', $this->acta())->json('data.id');

        // Ahora entra el otro tenant.
        $this->operador();

        $this->getJson('/api/v1/e14/actas')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/e14/actas/{$ajena}")->assertStatus(404);
        $this->putJson("/api/v1/e14/actas/{$ajena}", ['votos_urna' => 1])->assertStatus(404);
        $this->getJson('/api/v1/e14/consolidado')->assertStatus(200)->assertJsonPath('meta.total_votos', 0);
    }

    public function test_dos_tenants_pueden_tener_la_misma_mesa_sin_pisarse(): void
    {
        $primero = Tenant::factory()->create();
        $this->operador([Permissions::VIEW_E14, Permissions::MANAGE_E14], $primero);
        $this->postJson('/api/v1/e14/actas', $this->acta())->assertStatus(201);

        $segundo = Tenant::factory()->create();
        $this->operador([Permissions::VIEW_E14, Permissions::MANAGE_E14], $segundo);
        $this->postJson('/api/v1/e14/actas', $this->acta([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 40],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 40],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]))->assertStatus(201);

        $this->assertSame(2, E14Acta::withoutGlobalScope(TenantScope::class)->count());
        $this->getJson('/api/v1/e14/consolidado')->assertJsonPath('meta.total_candidatos', 99);
    }
}

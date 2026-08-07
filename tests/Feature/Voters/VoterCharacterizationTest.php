<?php

namespace Tests\Feature\Voters;

use App\Models\Call;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\TipoVotante;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del módulo de votantes (Spec 0011).
 *
 * Fija el contrato **observado** de `/voters`, sus endpoints especiales y el
 * catálogo `/voter-types`. No corrige nada: lo que sale raro se anota en
 * `.specify/context/known-issues.md` y se documenta en
 * `docs/VOTERS_SURVEYS_API.md`.
 *
 * Todo el módulo va con un permiso único, `view_voters`: no hay
 * create/edit/delete separados.
 */
class VoterCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$user, $token] = $this->createTenantWithUser(['view_voters']);
        $this->tenant = $user->tenant;
        $this->token = $token;
        $this->actingAsTenantUser($user, $token);
    }

    // ==================================================================
    // CRUD
    // ==================================================================

    public function test_el_listado_pagina_y_solo_trae_los_del_tenant(): void
    {
        Voter::factory()->forTenant($this->tenant)->count(2)->create();
        Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $respuesta = $this->getJson('/api/v1/voters')->assertStatus(200);

        $respuesta->assertJsonCount(2, 'data');
        $respuesta->assertJsonStructure([
            'data' => [['id', 'cedula', 'nombres', 'apellidos', 'full_name', 'tipo_votante_id']],
            'meta' => ['total', 'current_page', 'per_page', 'last_page'],
        ]);
    }

    public function test_el_listado_busca_por_cedula_nombre_correo_o_telefono(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['nombres' => 'Ana', 'apellidos' => 'Restrepo']);
        Voter::factory()->forTenant($this->tenant)->create(['nombres' => 'Beatriz', 'apellidos' => 'Salazar']);

        $this->getJson('/api/v1/voters?search=Restrepo')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombres', 'Ana');
    }

    public function test_crea_un_votante_y_le_pone_elector_por_defecto(): void
    {
        // `tipo_votante_id` vacío → 1 en duro en el controlador, no el
        // «Elector» por descripción.
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        $respuesta = $this->postJson('/api/v1/voters', [
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ])->assertStatus(201);

        $respuesta->assertJsonPath('message', 'Votante creado exitosamente');
        $respuesta->assertJsonPath('data.tipo_votante_id', 1);
        $respuesta->assertJsonPath('data.tenant_id', $this->tenant->id);

        $this->assertDatabaseHas('voters', [
            'cedula' => '71000001',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_la_cedula_es_unica_dentro_del_tenant_pero_no_entre_tenants(): void
    {
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001']);

        $this->postJson('/api/v1/voters', [
            'cedula' => '71000001',
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
        ])->assertStatus(422)->assertJsonValidationErrors(['cedula']);

        // La misma cédula en otra campaña es otra persona y sí se admite.
        $otro = Tenant::factory()->create();
        Voter::factory()->forTenant($otro)->create(['cedula' => '71000001']);

        $this->assertSame(
            2,
            Voter::withoutGlobalScope(TenantScope::class)->where('cedula', '71000001')->count()
        );
    }

    public function test_show_carga_las_relaciones_incluidas_las_llamadas(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();
        Call::factory()->forTenant($this->tenant)->create(['voter_id' => $votante->id]);

        $this->getJson("/api/v1/voters/{$votante->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $votante->id)
            ->assertJsonCount(1, 'data.calls');
    }

    public function test_actualizar_exige_reenviar_cedula_nombres_y_apellidos(): void
    {
        // No hay actualización parcial: `UpdateVoterRequest` marca los tres como
        // `required`, y `apiResource` apunta PUT **y PATCH** al mismo método. Un
        // PATCH con un solo campo se rechaza.
        $votante = Voter::factory()->forTenant($this->tenant)->create(['nombres' => 'Ana']);

        $this->putJson("/api/v1/voters/{$votante->id}", ['nombres' => 'Ana María'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula', 'apellidos']);

        $this->patchJson("/api/v1/voters/{$votante->id}", ['nombres' => 'Ana María'])
            ->assertStatus(422);

        $this->putJson("/api/v1/voters/{$votante->id}", [
            'cedula' => $votante->cedula,
            'nombres' => 'Ana María',
            'apellidos' => $votante->apellidos,
        ])->assertStatus(200)->assertJsonPath('data.nombres', 'Ana María');
    }

    public function test_borra_en_blando(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();

        $this->deleteJson("/api/v1/voters/{$votante->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Votante eliminado exitosamente');

        $this->assertSoftDeleted('voters', ['id' => $votante->id]);
    }

    // ==================================================================
    // Endpoints especiales
    // ==================================================================

    public function test_buscar_por_cedula_devuelve_404_cuando_no_hay_nadie(): void
    {
        // No es una lista vacía: es un 404 con envoltorio de error. El cliente
        // tiene que tratar «no encontrado» como fallo.
        $this->getJson('/api/v1/voters/search/by-cedula?cedula=99999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Votante no encontrado');
    }

    public function test_buscar_por_cedula_encuentra_al_del_tenant_y_no_al_de_otro(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001', 'nombres' => 'Ana']);

        $this->getJson('/api/v1/voters/search/by-cedula?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('data.nombres', 'Ana');

        $otro = Tenant::factory()->create();
        Voter::factory()->forTenant($otro)->create(['cedula' => '72000009']);

        $this->getJson('/api/v1/voters/search/by-cedula?cedula=72000009')->assertStatus(404);
    }

    public function test_buscar_por_cedula_exige_el_parametro(): void
    {
        $this->getJson('/api/v1/voters/search/by-cedula')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula']);
    }

    public function test_las_estadisticas_cuentan_solo_el_tenant(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'email' => 'ana@ejemplo.test', 'telefono' => '3001112233', 'mesa_votacion' => '012',
        ]);
        Voter::factory()->forTenant($this->tenant)->create([
            'email' => null, 'telefono' => null, 'mesa_votacion' => null,
        ]);
        Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $datos = $this->getJson('/api/v1/voters-stats')->assertStatus(200)->json('data');

        $this->assertSame(2, $datos['total']);
        $this->assertSame(1, $datos['with_email']);
        $this->assertSame(1, $datos['with_phone']);
        $this->assertSame(1, $datos['with_voting_info']);
        $this->assertSame(0, $datos['with_multiple_records']);
        $this->assertArrayHasKey('by_location_type', $datos);
    }

    public function test_by_voting_place_separa_tolima_del_resto_del_pais(): void
    {
        // El endpoint agrupa por el TEXTO de `voters.puesto_votacion`, no por la
        // tabla `voting_places`, y trata «TOLIMA» como caso especial en duro.
        Voter::factory()->forTenant($this->tenant)->create([
            'nombres' => 'Ana', 'apellidos' => 'Restrepo',
            'departamento_votacion' => 'TOLIMA', 'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO', 'mesa_votacion' => '012',
        ]);
        Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => 'TOLIMA', 'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ]);
        Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => 'CUNDINAMARCA', 'municipio_votacion' => 'BOGOTA',
            'puesto_votacion' => 'COLEGIO NORTE',
        ]);

        $datos = $this->getJson('/api/v1/voters-by-voting-place')->assertStatus(200)->json('data');

        $this->assertSame(1, $datos['total_puestos']);
        $this->assertSame(2, $datos['total_votantes_tolima']);
        $this->assertSame('IE EL CENTRO', $datos['puestos'][0]['puesto_votacion']);
        $this->assertCount(2, $datos['puestos'][0]['detalle_votacion']);

        // Quien vota fuera del Tolima no se agrupa: sale en una lista aparte.
        $this->assertSame(1, $datos['total_votantes_externos']);
        $this->assertSame('CUNDINAMARCA', $datos['votantes_externos'][0]['departamento_votacion']);
    }

    public function test_by_voting_place_ignora_a_quien_no_tiene_departamento(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => null, 'puesto_votacion' => 'SIN DEPARTAMENTO',
        ]);

        $datos = $this->getJson('/api/v1/voters-by-voting-place')->assertStatus(200)->json('data');

        $this->assertSame(0, $datos['total_puestos']);
        $this->assertSame(0, $datos['total_votantes_externos']);
    }

    // ==================================================================
    // Vínculo con la asistencia (Spec 0022)
    // ==================================================================

    public function test_el_votante_es_la_persona_a_la_que_apunta_la_asistencia(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001']);
        $reunion = Meeting::factory()->forTenant($this->tenant)->create();

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'meeting_id' => $reunion->id,
            'voter_id' => $votante->id,
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ]);

        $this->assertSame($votante->id, $asistente->voter_id);
        // Borrar al votante no borra la asistencia: se pierde el vínculo.
        $votante->forceDelete();
        $this->assertNull($asistente->fresh()->voter_id);
    }

    // ==================================================================
    // Catálogo de tipos de votante
    // ==================================================================

    public function test_los_tipos_de_votante_son_un_catalogo_global_no_por_tenant(): void
    {
        // `TipoVotante` no usa `HasTenant`: la tabla es compartida. Lo que cree
        // una campaña lo ven todas.
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        $this->postJson('/api/v1/voter-types', ['descripcion' => 'Líder de cuadra'])
            ->assertStatus(201)
            ->assertJsonPath('data.descripcion', 'Líder de cuadra');

        [$otroUsuario, $otroToken] = $this->createTenantWithUser(['view_voters']);

        // El `current_tenant_id` de la petición anterior sigue enlazado en el
        // contenedor —que en pruebas se comparte entre peticiones—, y con él
        // `User::find()` de `EnsureTenant` no vería al usuario del otro tenant.
        app()->forgetInstance('current_tenant_id');

        $descripciones = $this->actingAsTenantUser($otroUsuario, $otroToken)
            ->getJson('/api/v1/voter-types')
            ->assertStatus(200)
            ->json('data.*.descripcion');

        $this->assertContains('Líder de cuadra', $descripciones);
    }

    public function test_la_descripcion_del_tipo_es_unica_globalmente(): void
    {
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        $this->postJson('/api/v1/voter-types', ['descripcion' => 'Elector'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_el_tipo_por_defecto_no_se_puede_borrar(): void
    {
        // La protección es por id === 1, no por descripción.
        $elector = TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        $respuesta = $this->deleteJson("/api/v1/voter-types/{$elector->id}");

        if ($elector->id === 1) {
            $respuesta->assertStatus(400)
                ->assertJsonPath('message', 'No se puede eliminar el tipo de votante por defecto.');
        } else {
            $respuesta->assertStatus(200);
        }
    }

    public function test_los_tipos_de_votante_se_borran_en_blando(): void
    {
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);
        $tipo = TipoVotante::create(['descripcion' => 'Simpatizante']);

        $this->deleteJson("/api/v1/voter-types/{$tipo->id}")->assertStatus(200);

        $this->assertSoftDeleted('tipo_votante', ['id' => $tipo->id]);
    }

    // ==================================================================
    // Permisos y aislamiento
    // ==================================================================

    public function test_sin_view_voters_todo_el_modulo_responde_403(): void
    {
        [$user, $token] = $this->createTenantWithUser([], $this->tenant);
        $this->actingAsTenantUser($user, $token);

        foreach ([
            '/api/v1/voters',
            '/api/v1/voters-stats',
            '/api/v1/voters-by-voting-place',
            '/api/v1/voters/search/by-cedula?cedula=71000001',
            '/api/v1/voter-types',
        ] as $ruta) {
            $this->getJson($ruta)->assertStatus(403);
        }
    }

    public function test_sin_sesion_todo_el_modulo_responde_401(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/voters')->assertStatus(401);
        $this->getJson('/api/v1/voter-types')->assertStatus(401);
    }

    public function test_un_votante_de_otro_tenant_da_404_en_show_update_y_destroy(): void
    {
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $this->getJson("/api/v1/voters/{$ajeno->id}")->assertStatus(404);
        $this->putJson("/api/v1/voters/{$ajeno->id}", ['nombres' => 'Robado'])->assertStatus(404);
        $this->deleteJson("/api/v1/voters/{$ajeno->id}")->assertStatus(404);
    }
}

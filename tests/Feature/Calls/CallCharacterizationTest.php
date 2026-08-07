<?php

namespace Tests\Feature\Calls;

use App\Models\Call;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN de llamadas y puestos de votación (Spec 0011).
 *
 * Las llamadas van bajo `view_calls`, el mismo permiso que las encuestas. Los
 * puestos de votación **no tienen CRUD**: `VotingPlaceController` solo expone la
 * generación de imagen y el envío por WhatsApp (fuera del alcance de esta spec),
 * y la tabla se alimenta únicamente desde el webhook de Registraduría.
 *
 * No se corrige nada: los hallazgos van a `known-issues.md`.
 */
class CallCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        [$user, $token] = $this->createTenantWithUser(['view_calls']);
        $this->tenant = $user->tenant;
        $this->usuario = $user;
        $this->actingAsTenantUser($user, $token);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // CRUD
    // ==================================================================

    public function test_el_listado_pagina_y_solo_trae_las_del_tenant(): void
    {
        Call::factory()->forTenant($this->tenant)->count(2)->create();
        Call::factory()->forTenant(Tenant::factory()->create())->create();

        $this->getJson('/api/v1/calls')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['success', 'data', 'pagination' => ['total', 'per_page', 'current_page']]);
    }

    public function test_el_listado_filtra_por_estado_y_por_encuesta(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        Call::factory()->forTenant($this->tenant)->create(['survey_id' => $encuesta->id]);
        Call::factory()->forTenant($this->tenant)->status('no_answer')->create();

        $this->getJson('/api/v1/calls?status=no_answer')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/calls?survey_id={$encuesta->id}")->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_registra_una_llamada_con_sus_respuestas(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        $pregunta = SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => '¿Va a votar?', 'question_type' => 'yes_no',
        ]);

        $respuesta = $this->postJson('/api/v1/calls', [
            'voter_id' => $votante->id,
            'survey_id' => $encuesta->id,
            'call_date' => '2026-08-06 10:00:00',
            'duration_seconds' => 180,
            'status' => 'completed',
            'notes' => 'Muy receptiva',
            'responses' => [
                ['survey_question_id' => $pregunta->id, 'answer_text' => 'Sí'],
            ],
        ])->assertStatus(201);

        $respuesta->assertJsonPath('message', 'Llamada registrada exitosamente');
        $respuesta->assertJsonPath('data.tenant_id', $this->tenant->id);
        // El operador es quien está autenticado; no se puede suplantar.
        $respuesta->assertJsonPath('data.user_id', $this->usuario->id);
        $respuesta->assertJsonCount(1, 'data.responses');

        $this->assertDatabaseHas('survey_responses', [
            'survey_question_id' => $pregunta->id,
            'voter_id' => $votante->id,
            'answer_text' => 'Sí',
        ]);
    }

    public function test_la_duracion_viene_formateada_como_accessor(): void
    {
        $llamada = Call::factory()->forTenant($this->tenant)->create(['duration_seconds' => 125]);

        $this->getJson("/api/v1/calls/{$llamada->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.duration_formatted', '2:05');
    }

    public function test_hueco_el_controlador_acepta_un_estado_que_la_columna_rechaza(): void
    {
        // La validación admite siete estados; el enum de la columna tiene SEIS
        // —`pending` no está—. La inserción revienta y el `catch` la convierte
        // en un 500 con el mensaje de la base de datos dentro.
        $votante = Voter::factory()->forTenant($this->tenant)->create();

        $this->postJson('/api/v1/calls', [
            'voter_id' => $votante->id,
            'call_date' => '2026-08-06 10:00:00',
            'status' => 'pending',
        ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Error al registrar la llamada');

        $this->assertSame(0, Call::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_actualizar_y_borrar_en_duro(): void
    {
        $llamada = Call::factory()->forTenant($this->tenant)->create();

        $this->putJson("/api/v1/calls/{$llamada->id}", [
            'voter_id' => $llamada->voter_id,
            'call_date' => '2026-08-06 11:00:00',
            'status' => 'no_answer',
        ])->assertStatus(200)->assertJsonPath('data.status', 'no_answer');

        $this->deleteJson("/api/v1/calls/{$llamada->id}")->assertStatus(200);

        // `Call` no usa SoftDeletes: la llamada desaparece del histórico.
        $this->assertDatabaseMissing('calls', ['id' => $llamada->id]);
    }

    public function test_actualizar_no_toca_las_respuestas_de_la_encuesta(): void
    {
        $llamada = Call::factory()->forTenant($this->tenant)->create();

        $this->putJson("/api/v1/calls/{$llamada->id}", [
            'voter_id' => $llamada->voter_id,
            'call_date' => '2026-08-06 11:00:00',
            'status' => 'completed',
            'responses' => [['survey_question_id' => 1, 'answer_text' => 'Ignorada']],
        ])->assertStatus(200);

        // `update` ni valida ni guarda `responses`: solo se registran al crear.
        $this->assertDatabaseCount('survey_responses', 0);
    }

    // ==================================================================
    // Por votante y estadísticas
    // ==================================================================

    public function test_by_voter_devuelve_el_historial_sin_paginar(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();
        Call::factory()->forTenant($this->tenant)->count(2)->create(['voter_id' => $votante->id]);
        Call::factory()->forTenant($this->tenant)->create();

        $this->getJson("/api/v1/voters/{$votante->id}/calls")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('pagination');
    }

    public function test_by_voter_de_otro_tenant_da_404(): void
    {
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $this->getJson("/api/v1/voters/{$ajeno->id}/calls")->assertStatus(404);
    }

    public function test_by_voter_solo_pide_view_calls_no_view_voters(): void
    {
        // La ruta cuelga del grupo de call center, así que quien gestiona
        // llamadas lee el histórico de un votante sin tener `view_voters`.
        $votante = Voter::factory()->forTenant($this->tenant)->create();

        $this->getJson("/api/v1/voters/{$votante->id}/calls")->assertStatus(200);
    }

    public function test_las_estadisticas_resumen_el_trabajo_del_tenant(): void
    {
        Call::factory()->forTenant($this->tenant)->count(3)->create(['duration_seconds' => 60]);
        Call::factory()->forTenant($this->tenant)->status('no_answer')->create(['duration_seconds' => 30]);
        Call::factory()->forTenant(Tenant::factory()->create())->create();

        $datos = $this->getJson('/api/v1/calls-stats')->assertStatus(200)->json('data');

        $this->assertSame(4, $datos['total_calls']);
        $this->assertSame(3, $datos['by_status']['completed']);
        $this->assertSame(1, $datos['by_status']['no_answer']);
        $this->assertSame(75, $datos['completion_rate']);
        $this->assertSame(53, $datos['average_duration']);
        $this->assertArrayHasKey('by_survey', $datos);
        $this->assertArrayHasKey('by_user', $datos);
    }

    public function test_hueco_by_status_no_reporta_el_estado_pending(): void
    {
        // El mapa de salida enumera seis estados a mano; `pending`, que la
        // validación sí acepta, no aparece nunca.
        $datos = $this->getJson('/api/v1/calls-stats')->assertStatus(200)->json('data');

        $this->assertArrayNotHasKey('pending', $datos['by_status']);
    }

    public function test_hueco_unique_voters_contacted_solo_cuenta_llamadas_con_duracion(): void
    {
        // `$query` se reutiliza tras aplicarle `whereNotNull('duration_seconds')`
        // para la media, y ese filtro se arrastra a `total_duration` y a
        // `unique_voters_contacted`. Con dos votantes distintos, uno sin
        // duración, el contador dice 1.
        $conDuracion = Voter::factory()->forTenant($this->tenant)->create();
        $sinDuracion = Voter::factory()->forTenant($this->tenant)->create();

        Call::factory()->forTenant($this->tenant)->create([
            'voter_id' => $conDuracion->id, 'duration_seconds' => 90,
        ]);
        Call::factory()->forTenant($this->tenant)->create([
            'voter_id' => $sinDuracion->id, 'duration_seconds' => null,
        ]);

        $datos = $this->getJson('/api/v1/calls-stats')->assertStatus(200)->json('data');

        $this->assertSame(2, $datos['total_calls'], 'El total sí cuenta las dos.');
        $this->assertSame(1, $datos['unique_voters_contacted'], 'Pero aquí se pierde una.');
    }

    public function test_las_estadisticas_filtran_por_fecha(): void
    {
        Call::factory()->forTenant($this->tenant)->create(['call_date' => '2026-07-01 10:00:00']);
        Call::factory()->forTenant($this->tenant)->create(['call_date' => '2026-08-05 10:00:00']);

        $datos = $this->getJson('/api/v1/calls-stats?date_from=2026-08-01')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(1, $datos['total_calls']);
    }

    // ==================================================================
    // Aislamiento y permisos
    // ==================================================================

    public function test_una_llamada_de_otro_tenant_da_404(): void
    {
        $ajena = Call::factory()->forTenant(Tenant::factory()->create())->create();

        $this->getJson("/api/v1/calls/{$ajena->id}")->assertStatus(404);
        $this->deleteJson("/api/v1/calls/{$ajena->id}")->assertStatus(404);
    }

    public function test_hueco_se_puede_registrar_una_llamada_contra_un_votante_de_otro_tenant(): void
    {
        // `voter_id => exists:voters,id` consulta la tabla en crudo, sin el
        // `TenantScope`, así que la validación no impide apuntar a una persona
        // de otra campaña. La llamada se crea con el tenant de quien la
        // registra y una `voter_id` que no le pertenece.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create([
            'nombres' => 'Ana María',
            'telefono' => '3001112233',
        ]);

        $llamada = $this->postJson('/api/v1/calls', [
            'voter_id' => $ajeno->id,
            'call_date' => '2026-08-06 10:00:00',
            'duration_seconds' => 60,
            'status' => 'completed',
        ])->assertStatus(201)->json('data.id');

        $this->assertSame($ajeno->id, Call::findOrFail($llamada)->voter_id);
        $this->assertSame($this->tenant->id, Call::findOrFail($llamada)->tenant_id);

        // Los datos del votante ajeno NO se filtran: `Voter` usa `HasTenant`, y
        // la relación se carga vacía. El daño es de integridad —una llamada que
        // cuenta como contacto de alguien que no es del tenant— y de conteo:
        // `unique_voters_contacted` la suma.
        $this->getJson("/api/v1/calls/{$llamada}")
            ->assertStatus(200)
            ->assertJsonPath('data.voter', null);

        $this->assertSame(
            1,
            $this->getJson('/api/v1/calls-stats')->json('data.unique_voters_contacted')
        );
    }

    public function test_hueco_tambien_se_puede_apuntar_a_una_encuesta_de_otro_tenant(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();
        $encuestaAjena = Survey::factory()->forTenant(Tenant::factory()->create())
            ->create(['titulo' => 'Sondeo de la competencia']);

        $llamada = $this->postJson('/api/v1/calls', [
            'voter_id' => $votante->id,
            'survey_id' => $encuestaAjena->id,
            'call_date' => '2026-08-06 10:00:00',
            'status' => 'completed',
        ])->assertStatus(201)->json('data.id');

        // El título ajeno no se filtra —`Survey` sí usa `HasTenant`— pero el
        // vínculo queda escrito y cuenta en las estadísticas de la otra campaña.
        $this->getJson("/api/v1/calls/{$llamada}")
            ->assertStatus(200)
            ->assertJsonPath('data.survey', null);

        $this->assertSame($encuestaAjena->id, Call::find($llamada)->survey_id);
    }

    public function test_sin_view_calls_las_llamadas_responden_403(): void
    {
        [$user, $token] = $this->createTenantWithUser([], $this->tenant);
        $this->actingAsTenantUser($user, $token);

        $this->getJson('/api/v1/calls')->assertStatus(403);
        $this->getJson('/api/v1/calls-stats')->assertStatus(403);
    }

    public function test_sin_sesion_401(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/calls')->assertStatus(401);
    }

    // ==================================================================
    // Puestos de votación
    // ==================================================================

    public function test_hueco_los_puestos_de_votacion_no_tienen_ninguna_ruta_crud(): void
    {
        // `VotingPlaceController` solo expone generación de imagen y envío por
        // WhatsApp, ambas públicas. No hay index/show/store/update/destroy: la
        // tabla solo se alimenta desde el webhook de Registraduría y no se puede
        // consultar ni corregir por API.
        $rutas = collect(app('router')->getRoutes())
            ->map(fn ($ruta) => $ruta->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'voting-place/'))
            ->values()
            ->all();

        $this->assertSame([
            'api/v1/voting-place/generate-image',
            'api/v1/voting-place/send-whatsapp',
        ], $rutas);

        $this->getJson('/api/v1/voting-places')->assertStatus(404);
    }

    public function test_el_puesto_de_votacion_es_un_catalogo_global_sin_tenant(): void
    {
        // `VotingPlace` no usa `HasTenant` ni tiene columna `tenant_id`: un
        // puesto creado por el webhook para una campaña lo comparten todas.
        $puesto = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ]);

        $this->assertFalse(\Schema::hasColumn('voting_places', 'tenant_id'));

        $deA = Voter::factory()->forTenant($this->tenant)->create(['voting_place_id' => $puesto->id]);
        $deB = Voter::factory()->forTenant(Tenant::factory()->create())->create(['voting_place_id' => $puesto->id]);

        $this->assertSame(2, $puesto->voters()->count());
        $this->assertSame($puesto->id, $deA->voting_place_id);
        $this->assertSame($puesto->id, $deB->voting_place_id);
    }

    public function test_hueco_by_voting_place_ignora_la_tabla_de_puestos(): void
    {
        // Hay dos nociones paralelas de «puesto»: la tabla `voting_places` y el
        // texto `voters.puesto_votacion`. El endpoint que el frontend consume
        // agrupa por el TEXTO, así que un votante ligado a un puesto pero sin el
        // texto no aparece por ningún lado.
        $puesto = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ]);

        Voter::factory()->forTenant($this->tenant)->create([
            'voting_place_id' => $puesto->id,
            'puesto_votacion' => null,
            'departamento_votacion' => null,
        ]);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);
        $datos = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/voters-by-voting-place')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(0, $datos['total_puestos']);
        $this->assertSame(0, $datos['total_votantes_externos']);
    }
}

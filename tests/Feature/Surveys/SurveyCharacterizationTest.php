<?php

namespace Tests\Feature\Surveys;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN de encuestas y preguntas (Spec 0011).
 *
 * Las encuestas van bajo `view_calls`, no bajo un permiso propio: el módulo de
 * call center es un bloque único. Las preguntas cuelgan de la encuesta pero sus
 * rutas son `shallow()`, así que `GET/PUT/DELETE /questions/{id}` se resuelven
 * solo por id: desde la Spec 0031 el binding filtra por tenant porque
 * `SurveyQuestion` usa `HasTenant`.
 *
 * Los `test_hueco_*` que quedan son rarezas de contrato pendientes de spec
 * propia (ver `known-issues.md`); la fuga cross-tenant ya es enforcement.
 */
class SurveyCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        [$user, $token] = $this->createTenantWithUser(['view_calls']);
        $this->tenant = $user->tenant;
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

    public function test_el_listado_pagina_fuera_de_data_y_solo_trae_las_del_tenant(): void
    {
        Survey::factory()->forTenant($this->tenant)->count(2)->create();
        Survey::factory()->forTenant(Tenant::factory()->create())->create();

        $respuesta = $this->getJson('/api/v1/surveys')->assertStatus(200);

        $respuesta->assertJsonCount(2, 'data');
        // La paginación va en `pagination`, no en `meta` como en votantes:
        // dos envoltorios distintos en el mismo API.
        $respuesta->assertJsonStructure([
            'success',
            'data',
            'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
        ]);
    }

    public function test_crear_una_encuesta_exige_al_menos_una_pregunta(): void
    {
        $this->postJson('/api/v1/surveys', ['titulo' => 'Sin preguntas'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['questions']]);
    }

    public function test_crea_la_encuesta_con_sus_preguntas_y_les_pone_orden(): void
    {
        $respuesta = $this->postJson('/api/v1/surveys', [
            'titulo' => 'Intención de voto',
            'descripcion' => 'Sondeo de barrio',
            'questions' => [
                ['question_text' => '¿Va a votar?', 'question_type' => 'yes_no'],
                ['question_text' => '¿Por quién?', 'question_type' => 'text', 'is_required' => true],
            ],
        ])->assertStatus(201);

        $respuesta->assertJsonPath('message', 'Encuesta creada exitosamente');
        $respuesta->assertJsonPath('data.tenant_id', $this->tenant->id);
        // Activa por defecto.
        $respuesta->assertJsonPath('data.is_active', true);
        $respuesta->assertJsonCount(2, 'data.questions');
        // El orden se autonumera 1..n cuando no viene.
        $respuesta->assertJsonPath('data.questions.0.order', 1);
        $respuesta->assertJsonPath('data.questions.1.order', 2);
        $respuesta->assertJsonPath('data.questions.1.is_required', true);

        // Accessors del modelo.
        $respuesta->assertJsonPath('data.questions_count', 2);
        $respuesta->assertJsonPath('data.is_current', true);
    }

    public function test_solo_se_admiten_cuatro_tipos_de_pregunta(): void
    {
        $this->postJson('/api/v1/surveys', [
            'titulo' => 'Tipos',
            'questions' => [['question_text' => '¿?', 'question_type' => 'dropdown']],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['questions.0.question_type']]);
    }

    public function test_hueco_las_opciones_se_codifican_dos_veces_y_vuelven_como_texto(): void
    {
        // El controlador hace `json_encode($options)` antes de guardar, pero
        // `SurveyQuestion::$casts` ya declara `options => array`, así que
        // Eloquent lo vuelve a codificar. Al leer, el cast decodifica una sola
        // vez y devuelve una CADENA JSON en vez del arreglo.
        $respuesta = $this->postJson('/api/v1/surveys', [
            'titulo' => 'Opciones',
            'questions' => [[
                'question_text' => '¿Cuál prefiere?',
                'question_type' => 'multiple_choice',
                'options' => json_encode(['Sí', 'No', 'No sabe']),
            ]],
        ])->assertStatus(201);

        $opciones = $respuesta->json('data.questions.0.options');

        $this->assertIsString($opciones, 'Debería ser un arreglo y llega como cadena.');
        // Y hace falta decodificar DOS veces para llegar al dato.
        $this->assertIsString(json_decode($opciones, true));
        $this->assertSame(['Sí', 'No', 'No sabe'], json_decode(json_decode($opciones, true), true));
    }

    public function test_hueco_crear_exige_options_como_cadena_json_pero_actualizar_acepta_cualquier_cosa(): void
    {
        // `store` valida `questions.*.options => nullable|json`: un arreglo real
        // no pasa. `update` y el controlador de preguntas usan `nullable` a
        // secas. Mismo campo, tres reglas distintas.
        $this->postJson('/api/v1/surveys', [
            'titulo' => 'Opciones',
            'questions' => [[
                'question_text' => '¿Cuál?',
                'question_type' => 'multiple_choice',
                'options' => ['Sí', 'No'],
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['questions.0.options']]);
    }

    public function test_actualizar_reemplaza_el_juego_de_preguntas(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        $vieja = SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => 'Vieja',
        ]);

        $this->putJson("/api/v1/surveys/{$encuesta->id}", [
            'titulo' => 'Actualizada',
            'questions' => [
                ['id' => $vieja->id, 'question_text' => 'Editada', 'question_type' => 'text'],
                ['question_text' => 'Nueva', 'question_type' => 'yes_no'],
            ],
        ])->assertStatus(200)->assertJsonCount(2, 'data.questions');

        $this->assertSame('Editada', $vieja->fresh()->question_text);
    }

    public function test_actualizar_borra_las_preguntas_que_no_vengan_en_la_lista(): void
    {
        // Borrado DURO: `SurveyQuestion` no usa SoftDeletes.
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        $sobrante = SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => 'Sobrante',
        ]);

        $this->putJson("/api/v1/surveys/{$encuesta->id}", [
            'titulo' => 'Actualizada',
            'questions' => [['question_text' => 'Única', 'question_type' => 'text']],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('survey_questions', ['id' => $sobrante->id]);
    }

    public function test_actualizar_sin_la_clave_questions_deja_las_preguntas_intactas(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => 'Se queda',
        ]);

        $this->putJson("/api/v1/surveys/{$encuesta->id}", ['titulo' => 'Solo el título'])
            ->assertStatus(200);

        $this->assertSame(1, $encuesta->fresh()->questions()->count());
    }

    public function test_borrar_la_encuesta_es_en_blando_y_no_toca_sus_preguntas(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        $pregunta = SurveyQuestion::factory()->forSurvey($encuesta)->create();

        $this->deleteJson("/api/v1/surveys/{$encuesta->id}")->assertStatus(200);

        $this->assertSoftDeleted('surveys', ['id' => $encuesta->id]);
        // La pregunta queda huérfana de una encuesta que ya no se lista.
        $this->assertDatabaseHas('survey_questions', ['id' => $pregunta->id]);
    }

    // ==================================================================
    // Activar / desactivar / clonar / vigentes
    // ==================================================================

    public function test_activar_y_desactivar(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->inactive()->create();

        $this->postJson("/api/v1/surveys/{$encuesta->id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Encuesta activada exitosamente');
        $this->assertTrue($encuesta->fresh()->is_active);

        $this->postJson("/api/v1/surveys/{$encuesta->id}/deactivate")->assertStatus(200);
        $this->assertFalse($encuesta->fresh()->is_active);
    }

    public function test_clonar_copia_las_preguntas_y_deja_la_copia_inactiva(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create(['titulo' => 'Original']);
        SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => '¿Va a votar?', 'question_type' => 'yes_no',
        ]);

        $respuesta = $this->postJson("/api/v1/surveys/{$encuesta->id}/clone")->assertStatus(201);

        $respuesta->assertJsonPath('data.titulo', 'Original - Copia');
        $respuesta->assertJsonPath('data.is_active', false);
        $respuesta->assertJsonCount(1, 'data.questions');
        $respuesta->assertJsonPath('data.questions.0.question_text', '¿Va a votar?');

        // La copia hereda el tenant del original, no el del usuario.
        $respuesta->assertJsonPath('data.tenant_id', $this->tenant->id);
    }

    public function test_clonar_admite_un_titulo_propio(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/surveys/{$encuesta->id}/clone", ['titulo' => 'Sondeo 2027'])
            ->assertStatus(201)
            ->assertJsonPath('data.titulo', 'Sondeo 2027');
    }

    public function test_surveys_active_respeta_la_vigencia_por_fechas(): void
    {
        Survey::factory()->forTenant($this->tenant)->create(['titulo' => 'Vigente']);
        Survey::factory()->forTenant($this->tenant)->inactive()->create(['titulo' => 'Inactiva']);
        Survey::factory()->forTenant($this->tenant)->create([
            'titulo' => 'Futura', 'starts_at' => now()->addWeek(),
        ]);
        Survey::factory()->forTenant($this->tenant)->create([
            'titulo' => 'Vencida', 'ends_at' => now()->subDay(),
        ]);

        $titulos = $this->getJson('/api/v1/surveys-active')
            ->assertStatus(200)
            ->json('data.*.titulo');

        $this->assertSame(['Vigente'], $titulos);
    }

    public function test_surveys_active_no_se_pagina(): void
    {
        Survey::factory()->forTenant($this->tenant)->count(3)->create();

        $this->getJson('/api/v1/surveys-active')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('pagination');
    }

    // ==================================================================
    // Preguntas por su propia ruta (shallow)
    // ==================================================================

    public function test_agregar_una_pregunta_a_una_encuesta_existente(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/surveys/{$encuesta->id}/questions", [
            'question_text' => '¿Conoce al candidato?',
            'question_type' => 'yes_no',
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Pregunta agregada exitosamente')
            ->assertJsonPath('data.order', 1);
    }

    public function test_el_orden_de_una_pregunta_nueva_continua_el_maximo(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        SurveyQuestion::factory()->forSurvey($encuesta)->create([
            'question_text' => 'Primera', 'order' => 7,
        ]);

        $this->postJson("/api/v1/surveys/{$encuesta->id}/questions", [
            'question_text' => 'Segunda', 'question_type' => 'text',
        ])->assertStatus(201)->assertJsonPath('data.order', 8);
    }

    public function test_mostrar_actualizar_y_borrar_una_pregunta(): void
    {
        $encuesta = Survey::factory()->forTenant($this->tenant)->create();
        $pregunta = SurveyQuestion::factory()->forSurvey($encuesta)->create();

        $this->getJson("/api/v1/questions/{$pregunta->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.survey.titulo', $encuesta->titulo);

        $this->putJson("/api/v1/questions/{$pregunta->id}", [
            'question_text' => '¿Conoce la propuesta?', 'question_type' => 'yes_no',
        ])->assertStatus(200)->assertJsonPath('data.question_text', '¿Conoce la propuesta?');

        $this->deleteJson("/api/v1/questions/{$pregunta->id}")->assertStatus(200);
        $this->assertDatabaseMissing('survey_questions', ['id' => $pregunta->id]);
    }

    // ==================================================================
    // Aislamiento y permisos
    // ==================================================================

    public function test_una_encuesta_de_otro_tenant_da_404(): void
    {
        $ajena = Survey::factory()->forTenant(Tenant::factory()->create())->create();

        $this->getJson("/api/v1/surveys/{$ajena->id}")->assertStatus(404);
        $this->putJson("/api/v1/surveys/{$ajena->id}", ['titulo' => 'Robada'])->assertStatus(404);
        $this->deleteJson("/api/v1/surveys/{$ajena->id}")->assertStatus(404);
        $this->postJson("/api/v1/surveys/{$ajena->id}/activate")->assertStatus(404);
        $this->postJson("/api/v1/surveys/{$ajena->id}/clone")->assertStatus(404);
    }

    public function test_las_preguntas_de_otro_tenant_no_se_leen_ni_se_escriben(): void
    {
        // Spec 0031: `SurveyQuestion` ya usa `HasTenant` (columna `tenant_id`
        // heredada de la encuesta), así que las rutas `shallow()` resuelven el
        // binding dentro del tenant. Detalle en
        // `tests/Feature/Surveys/SurveyQuestionTenantIsolationTest.php`.
        $ajena = Survey::factory()->forTenant(Tenant::factory()->create())->create();
        $preguntaAjena = SurveyQuestion::factory()->forSurvey($ajena)->create([
            'question_text' => 'Pregunta de otra campaña',
        ]);

        $this->getJson("/api/v1/questions/{$preguntaAjena->id}")->assertStatus(404);

        $this->putJson("/api/v1/questions/{$preguntaAjena->id}", [
            'question_text' => 'Editada desde otra campaña', 'question_type' => 'text',
        ])->assertStatus(404);

        $this->assertSame('Pregunta de otra campaña', $preguntaAjena->fresh()->question_text);

        $this->deleteJson("/api/v1/questions/{$preguntaAjena->id}")->assertStatus(404);
        $this->assertDatabaseHas('survey_questions', ['id' => $preguntaAjena->id]);
    }

    public function test_no_se_pueden_agregar_preguntas_a_una_encuesta_ajena(): void
    {
        // El alta siempre estuvo protegida: cuelga de `surveys/{survey}`, que
        // es tenant-scoped.
        $ajena = Survey::factory()->forTenant(Tenant::factory()->create())->create();

        $this->postJson("/api/v1/surveys/{$ajena->id}/questions", [
            'question_text' => 'Colada', 'question_type' => 'text',
        ])->assertStatus(404);
    }

    public function test_sin_view_calls_las_encuestas_responden_403(): void
    {
        [$user, $token] = $this->createTenantWithUser([], $this->tenant);
        $this->actingAsTenantUser($user, $token);

        $this->getJson('/api/v1/surveys')->assertStatus(403);
        $this->getJson('/api/v1/surveys-active')->assertStatus(403);
    }

    public function test_view_voters_no_alcanza_para_las_encuestas(): void
    {
        // Los dos permisos del módulo son independientes: quien gestiona
        // votantes no ve el call center y viceversa.
        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);
        $this->actingAsTenantUser($user, $token);

        $this->getJson('/api/v1/surveys')->assertStatus(403);
    }

    public function test_sin_sesion_401(): void
    {
        $this->flushHeaders();

        $this->getJson('/api/v1/surveys')->assertStatus(401);
    }

    public function test_el_super_admin_ve_las_encuestas_de_todos_los_tenants(): void
    {
        Survey::factory()->forTenant($this->tenant)->create();
        Survey::factory()->forTenant(Tenant::factory()->create())->create();

        $this->actingAsSuperAdmin();

        $this->getJson('/api/v1/surveys')->assertStatus(200)->assertJsonCount(2, 'data');

        $this->assertSame(2, Survey::withoutGlobalScope(TenantScope::class)->count());
    }
}

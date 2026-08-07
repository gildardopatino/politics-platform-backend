<?php

namespace Tests\Feature\Surveys;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Tests\TestCase;

/**
 * ENFORCEMENT del aislamiento de preguntas de encuesta (Spec 0031).
 *
 * Las rutas de preguntas son `shallow()`: `GET|PUT|DELETE /questions/{id}` se
 * resuelven solo por id. Sin `tenant_id` en el modelo, el binding alcanzaba toda
 * la tabla y una campaña leía, editaba y borraba las preguntas de otra
 * (hallazgo 0011). Aquí se fija que el contenido ajeno responde 404 y que el
 * CRUD propio sigue intacto.
 */
class SurveyQuestionTenantIsolationTest extends TestCase
{
    private Tenant $tenant;

    private Survey $propia;

    private SurveyQuestion $preguntaAjena;

    protected function setUp(): void
    {
        parent::setUp();

        [$user, $token] = $this->createTenantWithUser(['view_calls']);
        $this->tenant = $user->tenant;
        $this->actingAsTenantUser($user, $token);

        $this->propia = Survey::factory()->forTenant($this->tenant)->create();

        $encuestaAjena = Survey::factory()->forTenant(Tenant::factory()->create())->create();
        $this->preguntaAjena = SurveyQuestion::factory()
            ->forSurvey($encuestaAjena)
            ->create(['question_text' => 'Pregunta de otra campaña']);
    }

    // ==================================================================
    // Cross-tenant → 404
    // ==================================================================

    public function test_mostrar_una_pregunta_de_otro_tenant_da_404(): void
    {
        $this->getJson("/api/v1/questions/{$this->preguntaAjena->id}")
            ->assertStatus(404);
    }

    public function test_actualizar_una_pregunta_de_otro_tenant_da_404_y_no_la_toca(): void
    {
        $this->putJson("/api/v1/questions/{$this->preguntaAjena->id}", [
            'question_text' => 'Editada desde otra campaña',
            'question_type' => 'text',
        ])->assertStatus(404);

        $this->assertSame(
            'Pregunta de otra campaña',
            $this->preguntaAjena->fresh()->question_text
        );
    }

    public function test_borrar_una_pregunta_de_otro_tenant_da_404_y_la_deja_viva(): void
    {
        $this->deleteJson("/api/v1/questions/{$this->preguntaAjena->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('survey_questions', [
            'id' => $this->preguntaAjena->id,
        ]);
    }

    public function test_una_encuesta_propia_no_puede_adoptar_una_pregunta_ajena(): void
    {
        // `PUT /surveys/{propia}` acepta `questions[].id`: un id ajeno no puede
        // reescribirse ni quedar colgado de la encuesta propia.
        $this->putJson("/api/v1/surveys/{$this->propia->id}", [
            'titulo' => 'Con pregunta robada',
            'questions' => [[
                'id' => $this->preguntaAjena->id,
                'question_text' => 'Secuestrada',
                'question_type' => 'text',
            ]],
        ])->assertStatus(200);

        $ajena = $this->preguntaAjena->fresh();

        $this->assertSame('Pregunta de otra campaña', $ajena->question_text);
        $this->assertNotSame($this->propia->id, $ajena->survey_id);
    }

    // ==================================================================
    // El flujo legítimo sigue igual
    // ==================================================================

    public function test_el_crud_de_las_preguntas_propias_sigue_funcionando(): void
    {
        $creada = $this->postJson("/api/v1/surveys/{$this->propia->id}/questions", [
            'question_text' => '¿Conoce al candidato?',
            'question_type' => 'yes_no',
        ])->assertStatus(201)->json('data');

        $id = $creada['id'];

        $this->getJson("/api/v1/questions/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.question_text', '¿Conoce al candidato?')
            ->assertJsonPath('data.survey.titulo', $this->propia->titulo);

        $this->putJson("/api/v1/questions/{$id}", [
            'question_text' => '¿Conoce la propuesta?',
            'question_type' => 'yes_no',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.question_text', '¿Conoce la propuesta?');

        $this->deleteJson("/api/v1/questions/{$id}")->assertStatus(200);

        $this->assertDatabaseMissing('survey_questions', ['id' => $id]);
    }

    public function test_toda_pregunta_nueva_hereda_el_tenant_de_su_encuesta(): void
    {
        // Los cuatro caminos de alta: encuesta nueva con preguntas, ruta
        // anidada, `PUT` de la encuesta y clonado.
        $encuesta = $this->postJson('/api/v1/surveys', [
            'titulo' => 'Intención de voto',
            'questions' => [['question_text' => 'Anidada', 'question_type' => 'text']],
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/surveys/{$encuesta['id']}/questions", [
            'question_text' => 'Por su ruta', 'question_type' => 'text',
        ])->assertStatus(201);

        $this->putJson("/api/v1/surveys/{$encuesta['id']}", [
            'titulo' => 'Intención de voto',
            'questions' => [['question_text' => 'Por el PUT', 'question_type' => 'text']],
        ])->assertStatus(200);

        $this->postJson("/api/v1/surveys/{$encuesta['id']}/clone")->assertStatus(201);

        $this->assertSame(
            0,
            SurveyQuestion::withoutGlobalScope(TenantScope::class)
                ->where('survey_id', '!=', $this->preguntaAjena->survey_id)
                ->where('tenant_id', '!=', $this->tenant->id)
                ->count(),
            'Alguna pregunta quedó con el tenant equivocado.'
        );
    }

    public function test_la_encuesta_propia_lista_solo_sus_preguntas(): void
    {
        SurveyQuestion::factory()->forSurvey($this->propia)->create([
            'question_text' => 'Propia',
        ]);

        $this->getJson("/api/v1/surveys/{$this->propia->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.question_text', 'Propia');
    }
}

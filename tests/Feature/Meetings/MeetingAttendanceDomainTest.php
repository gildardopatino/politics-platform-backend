<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN frente a la intención de negocio (Spec 0010).
 *
 * `.specify/context/domain-meetings-attendance.md` describe para qué existe el
 * módulo: caracterizar a quien asiste y, sobre todo, medir **cuántos son
 * nuevos**. Estas pruebas contestan con evidencia ejecutable qué parte de ese
 * flujo ocurre hoy y cuál no.
 *
 * Lo que NO ocurre se fija igualmente: si mañana alguien lo implementa, estas
 * pruebas fallarán y habrá que actualizarlas — que es justo lo que se quiere.
 */
class MeetingAttendanceDomainTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-DOMINIO']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. ¿El check-in busca por documento y autocompleta?
    // ------------------------------------------------------------------

    public function test_hueco_el_check_in_no_consulta_al_votante_existente_por_cedula(): void
    {
        // Existe un votante con esa cédula y todos sus datos en la base.
        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
        ]);

        // El asistente escanea y escribe solo lo mínimo.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', [
            'cedula' => '71000001',
            'nombres' => 'ana',
            'apellidos' => 'restrepo',
        ])->assertStatus(201);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        // `checkIn` guarda literalmente lo que llegó: no mira `voters` ni el
        // recurso en línea de Registraduría. No hay autocompletado.
        $this->assertSame('ana', $asistente->nombres);
        $this->assertSame('restrepo', $asistente->apellidos);
        $this->assertNull($asistente->telefono, 'No se completó desde el votante existente.');
        $this->assertNull($asistente->email);

        // Y el asistente no queda ligado al votante de ninguna forma.
        $this->assertDatabaseMissing('meeting_attendees', ['id' => $asistente->id, 'cedula' => null]);
        $this->assertNotNull($votante->fresh(), 'El votante sigue ahí, sin relación con el asistente.');
    }

    public function test_el_lookup_por_documento_existe_pero_vive_en_otro_endpoint_y_exige_permiso(): void
    {
        // La pieza SÍ existe (`/voters/search/by-cedula`), pero es privada y
        // exige `view_voters`: no la puede usar el formulario público del QR.
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001', 'nombres' => 'Ana María']);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/voters/search/by-cedula?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('data.nombres', 'Ana María');

        // Sin sesión, que es la situación del formulario público: 401.
        // `actingAsTenantUser` deja la cabecera puesta para el resto de la prueba.
        $this->flushHeaders();
        $this->getJson('/api/v1/voters/search/by-cedula?cedula=71000001')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // 2. ¿Se deduplica por cédula?
    // ------------------------------------------------------------------

    public function test_hueco_no_hay_deduplicacion_por_cedula_ni_dentro_ni_entre_reuniones(): void
    {
        $otra = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-DOMINIO-2']);

        // Dos veces en la misma reunión.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        // Y una en otra reunión.
        $this->postJson("/api/v1/meetings/check-in/{$otra->qr_code}", $this->payload())->assertStatus(201);

        // Tres filas distintas para la misma persona: `meeting_attendees` no
        // tiene identidad por documento, solo filas por check-in.
        $this->assertDatabaseCount('meeting_attendees', 3);
        $this->assertSame(
            3,
            MeetingAttendee::withoutGlobalScope(TenantScope::class)->where('cedula', '71000001')->count()
        );
    }

    // ------------------------------------------------------------------
    // 3. Campos dinámicos del formulario público
    // ------------------------------------------------------------------

    public function test_la_info_publica_expone_los_campos_de_la_plantilla_para_pintar_el_formulario(): void
    {
        // Esta parte SÍ está cableada: la plantilla define las preguntas y
        // `getPublicInfo` las devuelve para que el frontend las renderice.
        $plantilla = MeetingTemplate::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->autorDelTenant()->id,
            'name' => 'Asamblea barrial',
            'description' => 'Formulario con preguntas extra',
            'fields' => [
                ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
                ['name' => 'acepta_datos', 'label' => 'Autoriza tratamiento de datos', 'type' => 'boolean'],
            ],
            'is_active' => true,
        ]);
        $this->meeting->update(['template_id' => $plantilla->id]);

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertStatus(200)
            ->assertJsonPath('data.template.nombre', 'Asamblea barrial')
            ->assertJsonPath('data.template.fields.0.name', 'profesion')
            ->assertJsonPath('data.template.fields.0.required', true);
    }

    public function test_hueco_el_check_in_no_valida_extra_fields_contra_la_plantilla(): void
    {
        $plantilla = MeetingTemplate::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->autorDelTenant()->id,
            'name' => 'Asamblea barrial',
            'fields' => [
                ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
            ],
            'is_active' => true,
        ]);
        $this->meeting->update(['template_id' => $plantilla->id]);

        // `extra_fields` es `nullable|array` y nada más: ni se exige el campo
        // marcado como required, ni se rechaza uno que la plantilla no declara.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'extra_fields' => ['campo_inventado' => 'cualquier cosa'],
        ]))->assertStatus(201);

        // Sin `profesion`, que la plantilla declara obligatorio.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'cedula' => '71000002',
        ]))->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    // ------------------------------------------------------------------
    // 4. Métrica de nuevos vs recurrentes
    // ------------------------------------------------------------------

    public function test_hueco_ningun_endpoint_distingue_asistentes_nuevos_de_recurrentes(): void
    {
        // Una persona que ya vino a otra reunión y otra que viene por primera vez.
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);
        $this->postJson("/api/v1/meetings/check-in/{$anterior->qr_code}", $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload(['cedula' => '71000999']))
            ->assertStatus(201);

        // La info pública solo sabe contar filas, no personas.
        $publico = $this->getJson('/api/v1/meetings/public/QR-DOMINIO')->assertStatus(200)->json('data');

        $this->assertSame(2, $publico['attendees_count']);
        $this->assertArrayNotHasKey('nuevos_count', $publico);
        $this->assertArrayNotHasKey('recurrentes_count', $publico);

        // El listado de asistentes tampoco marca cuál es nuevo.
        [$user, $token] = $this->createTenantWithUser(['view_meetings'], $this->tenant);

        $primero = $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees")
            ->assertStatus(200)
            ->json('data.0');

        $this->assertArrayNotHasKey('is_new', $primero);
        $this->assertArrayNotHasKey('es_nuevo', $primero);
        $this->assertArrayNotHasKey('meetings_count', $primero);
    }

    // ------------------------------------------------------------------
    // 5. Enlace reunión ↔ compromisos
    // ------------------------------------------------------------------

    public function test_la_reunion_enlaza_con_sus_compromisos(): void
    {
        $meeting = $this->meeting;
        \App\Models\Commitment::factory()->forTenant($this->tenant)->create([
            'meeting_id' => $meeting->id,
            'description' => 'Entregar el censo del sector',
        ]);
        // Un compromiso de otra reunión no debe aparecer.
        \App\Models\Commitment::factory()->forTenant($this->tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$meeting->id}/commitments")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Entregar el censo del sector')
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page', 'per_page']]);
    }

    // ------------------------------------------------------------------

    private function autorDelTenant(): User
    {
        [$user] = $this->createTenantWithUser([], $this->tenant);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ], $extra);
    }
}

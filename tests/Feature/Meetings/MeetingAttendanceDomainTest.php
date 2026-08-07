<?php

namespace Tests\Feature\Meetings;

use App\Models\Commitment;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTemplate;
use App\Models\Tenant;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN del flujo de asistencia frente a la intención de negocio
 * (Spec 0010, addendum).
 *
 * `.specify/context/domain-meetings-attendance.md` describe para qué existe el
 * módulo: caracterizar a quien asiste y medir **cuántos son nuevos**. Estas
 * pruebas contestan con evidencia ejecutable las cinco preguntas del addendum.
 *
 * Lo que NO ocurre se fija igualmente: si mañana alguien lo implementa, estas
 * pruebas fallarán y habrá que actualizarlas — que es justo lo que se quiere.
 *
 * Resumen en `docs/MEETINGS_API.md`, sección «Flujo de asistencia».
 */
class MeetingAttendanceDomainTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        // PISAMI es una API externa: nunca se llama de verdad desde la suite.
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-DOMINIO']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // 1. Búsqueda por documento en el check-in — PARCIAL
    // ==================================================================

    public function test_el_endpoint_publico_de_verificacion_vive_bajo_el_qr(): void
    {
        // El autocompletado del formulario público sigue sin pedir sesión, pero
        // ahora cuelga del QR, que es lo que fija el tenant de la búsqueda
        // (Spec 0026; antes era `GET /verify-document` a secas, sin tenant).
        Http::fake(['*pisami*' => Http::response('', 500)]);

        $this->crearLead($this->tenant, '71000001');

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('source', 'leads')
            ->assertJsonPath('data.nombres', 'Ana')
            ->assertJsonPath('data.telefono', '3001112233');
    }

    public function test_verify_document_ya_no_busca_leads_de_cualquier_tenant(): void
    {
        // Este hueco existía: la ruta caía fuera del grupo `tenant`, no había
        // `current_tenant_id` y `Lead::where('cedula', ...)` no filtraba, así que
        // cualquiera sin sesión sacaba los datos de un lead de OTRO tenant
        // sabiendo solo la cédula. Cerrado en la Spec 0026; el contrato completo
        // está en `tests/Feature/Voters/VerifyDocumentTenantScopeTest.php`.
        Http::fake(['*pisami*' => Http::response('', 500)]);

        $otro = Tenant::factory()->create();
        $this->crearLead($otro, '72000009');

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO/verify-document?cedula=72000009')
            ->assertStatus(404);

        $this->getJson('/api/v1/verify-document?cedula=72000009')
            ->assertStatus(401);
    }

    public function test_hueco_verify_document_no_mira_voters_ni_asistentes_previos(): void
    {
        // Sus dos fuentes son PISAMI (externa) y la tabla `leads`. Una persona
        // que ya está como VOTANTE del tenant, o que ya asistió a otra reunión,
        // no se encuentra: el autocompletado no la reconoce.
        Http::fake(['*pisami*' => Http::response('', 500)]);

        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'telefono' => '3001112233',
        ]);
        $this->meeting->attendees()->create([
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo',
            'telefono' => '3001112233',
        ]);

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO/verify-document?cedula=71000001')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_hueco_el_check_in_del_backend_no_hace_ningun_lookup(): void
    {
        // El autocompletado vive SOLO en el cliente (MeetingCheckIn.tsx llama a
        // verify-document y rellena el formulario). `MeetingController@checkIn`
        // guarda literalmente lo que llega: quien llame a la API directamente no
        // recibe enriquecimiento alguno.
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
        ]);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', [
            'cedula' => '71000001',
            'nombres' => 'ana',
            'apellidos' => 'restrepo',
        ])->assertStatus(201);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame('ana', $asistente->nombres);
        $this->assertNull($asistente->telefono, 'No se completó desde el votante existente.');
        $this->assertNull($asistente->email);
    }

    public function test_hueco_el_lookup_sobre_voters_existe_pero_es_privado(): void
    {
        // `searchByCedula` sí busca en `voters`, pero exige `view_voters`: el
        // formulario público del QR no puede usarlo. Por eso el check-in acabó
        // usando `verify-document`, que no mira `voters`.
        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001', 'nombres' => 'Ana María']);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/voters/search/by-cedula?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('data.nombres', 'Ana María');

        // Sin sesión, que es la situación del formulario público: 401.
        $this->flushHeaders();
        $this->getJson('/api/v1/voters/search/by-cedula?cedula=71000001')->assertStatus(401);
    }

    public function test_hueco_el_check_in_no_crea_ni_actualiza_el_votante(): void
    {
        // Quien asiste no pasa a ser votante del tenant: los webhooks de
        // Registraduría alimentan `voters`, pero el check-in no los toca. Las
        // dos bases de personas quedan desconectadas.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);

        $this->assertDatabaseCount('voters', 0);
    }

    // ==================================================================
    // 2. Deduplicación por cédula — NO
    // ==================================================================

    public function test_hueco_un_segundo_check_in_del_mismo_documento_duplica_en_vez_de_actualizar(): void
    {
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'telefono' => '3001112233',
        ]))->assertStatus(201);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'telefono' => '3009998877',
        ]))->assertStatus(201);

        $filas = MeetingAttendee::withoutGlobalScope(TenantScope::class)
            ->where('cedula', '71000001')
            ->orderBy('id')
            ->get();

        // Dos filas, no una actualizada.
        $this->assertCount(2, $filas);
        $this->assertSame('3001112233', $filas[0]->telefono);
        $this->assertSame('3009998877', $filas[1]->telefono);

        // Y el contador de la reunión los cuenta como dos asistentes.
        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertJsonPath('data.attendees_count', 2)
            ->assertJsonPath('data.checked_in_count', 2);
    }

    public function test_hueco_tampoco_hay_identidad_de_persona_entre_reuniones(): void
    {
        $otra = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-DOMINIO-2']);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson("/api/v1/meetings/check-in/{$otra->qr_code}", $this->payload())->assertStatus(201);

        // `meeting_attendees` no tiene ninguna clave que ligue ambas filas a la
        // misma persona más allá del texto de la cédula.
        $filas = MeetingAttendee::withoutGlobalScope(TenantScope::class)->get();

        $this->assertCount(2, $filas);
        $this->assertNotSame($filas[0]->id, $filas[1]->id);
        $this->assertEmpty(
            array_intersect(['person_id', 'voter_id', 'lead_id'], array_keys($filas[0]->getAttributes())),
            'No hay columna que identifique a la persona.'
        );
    }

    // ==================================================================
    // 3. Campos dinámicos — PARCIAL
    // ==================================================================

    public function test_los_campos_dinamicos_se_configuran_en_la_plantilla_de_reunion(): void
    {
        // Se guardan en `meeting_templates.fields` (JSON), a nivel de TENANT, y
        // la reunión los usa vía `template_id`. No hay config por reunión ni en
        // los ajustes del tenant.
        $plantilla = $this->plantillaCon([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
            ['name' => 'acepta_datos', 'label' => 'Autoriza tratamiento', 'type' => 'checkbox'],
        ]);

        $this->assertSame($this->tenant->id, $plantilla->tenant_id);
        $this->assertIsArray($plantilla->fields);

        $this->meeting->update(['template_id' => $plantilla->id]);

        // `getPublicInfo` los expone para que el formulario los pinte.
        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertStatus(200)
            ->assertJsonPath('data.template.nombre', 'Asamblea barrial')
            ->assertJsonPath('data.template.fields.0.name', 'profesion')
            ->assertJsonPath('data.template.fields.0.required', true);
    }

    public function test_las_respuestas_dinamicas_se_guardan_en_extra_fields(): void
    {
        $this->meeting->update(['template_id' => $this->plantillaCon([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ])->id]);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'extra_fields' => ['profesion' => 'Docente'],
        ]))->assertStatus(201);

        $asistente = MeetingAttendee::withoutGlobalScope(TenantScope::class)->firstOrFail();

        // Columna JSON del asistente; no hay tabla de respuestas.
        $this->assertSame(['profesion' => 'Docente'], $asistente->extra_fields);
    }

    public function test_hueco_el_backend_no_valida_extra_fields_contra_la_plantilla(): void
    {
        $this->meeting->update(['template_id' => $this->plantillaCon([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ])->id]);

        // `CheckInRequest` valida `extra_fields` como `nullable|array` y nada
        // más: la obligatoriedad solo la aplica el formulario del frontend.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'extra_fields' => ['campo_que_la_plantilla_no_declara' => 'lo que sea'],
        ]))->assertStatus(201);

        // Y sin el campo marcado como required, también pasa.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload(['cedula' => '71000002']))
            ->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    public function test_hueco_una_reunion_sin_plantilla_no_expone_campos_dinamicos(): void
    {
        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertStatus(200)
            ->assertJsonPath('data.template', null);
    }

    // ==================================================================
    // 4. Nuevos vs recurrentes — NO
    // ==================================================================

    public function test_hueco_ningun_endpoint_de_estadisticas_distingue_nuevos_de_recurrentes(): void
    {
        // Escenario: Ana ya vino a otra reunión (recurrente) y Beatriz viene por
        // primera vez (nueva). Lo que se debería poder medir: 1 nueva, 1 recurrente.
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);
        $this->postJson("/api/v1/meetings/check-in/{$anterior->qr_code}", $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'cedula' => '72000002', 'nombres' => 'Beatriz',
        ]))->assertStatus(201);

        [$user, $token] = $this->createTenantWithUser(
            ['view_meetings', 'view_reports'],
            $this->tenant
        );

        // a) Info pública de la reunión: solo cuenta filas.
        $publico = $this->getJson('/api/v1/meetings/public/QR-DOMINIO')->json('data');
        $this->assertSame(2, $publico['attendees_count']);
        $this->assertArrayNotHasKey('nuevos_count', $publico);
        $this->assertArrayNotHasKey('recurrentes_count', $publico);

        // b) Listado de asistentes: ninguna marca por asistente.
        $asistente = $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees")
            ->assertStatus(200)
            ->json('data.0');
        foreach (['is_new', 'es_nuevo', 'meetings_count', 'previous_meetings'] as $clave) {
            $this->assertArrayNotHasKey($clave, $asistente);
        }

        // c) reports/meetings: totales de filas, sin distinct por cédula.
        $reporte = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/reports/meetings')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame(3, $reporte['total_attendees'], 'Cuenta filas, no personas.');
        $this->assertArrayNotHasKey('unique_attendees', $reporte);
        $this->assertArrayNotHasKey('new_attendees', $reporte);

        // d) attendee-hierarchies/stats: cuenta cédulas distintas, pero de
        //    RELACIONES de jerarquía, no de asistencia.
        $jerarquia = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/attendee-hierarchies/stats')
            ->assertStatus(200)
            ->json('data');
        $this->assertArrayHasKey('unique_attendees', $jerarquia);
        $this->assertSame(0, $jerarquia['unique_attendees'], 'Sin relaciones de jerarquía: 0, pese a haber 3 check-ins.');
        $this->assertArrayNotHasKey('new_attendees', $jerarquia);
    }

    public function test_el_dato_para_calcular_nuevos_vs_recurrentes_esta_en_la_base_pero_nadie_lo_expone(): void
    {
        // La consulta es trivial —agrupar por cédula— pero ningún endpoint la
        // hace. Se deja escrita aquí como prueba de que el dato existe.
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);
        $this->postJson("/api/v1/meetings/check-in/{$anterior->qr_code}", $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'cedula' => '72000002', 'nombres' => 'Beatriz',
        ]))->assertStatus(201);

        $cedulasDeEstaReunion = MeetingAttendee::withoutGlobalScope(TenantScope::class)
            ->where('meeting_id', $this->meeting->id)
            ->pluck('cedula');

        $recurrentes = MeetingAttendee::withoutGlobalScope(TenantScope::class)
            ->whereIn('cedula', $cedulasDeEstaReunion)
            ->where('meeting_id', '!=', $this->meeting->id)
            ->distinct()
            ->pluck('cedula');

        $this->assertSame(['71000001'], $recurrentes->all(), 'Ana es recurrente.');
        $this->assertSame(1, $cedulasDeEstaReunion->diff($recurrentes)->count(), 'Beatriz es nueva.');
    }

    // ==================================================================
    // 5. Reunión → compromisos — SÍ
    // ==================================================================

    public function test_la_reunion_enlaza_con_sus_compromisos(): void
    {
        Commitment::factory()->forTenant($this->tenant)->create([
            'meeting_id' => $this->meeting->id,
            'description' => 'Entregar el censo del sector',
        ]);
        // Un compromiso de otra reunión no debe aparecer.
        Commitment::factory()->forTenant($this->tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/commitments")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Entregar el censo del sector')
            ->assertJsonPath('data.0.meeting_id', $this->meeting->id)
            // El objeto `meeting` no viene: `byMeeting` solo carga assignedUser y
            // priority. Se puede pedir con `?include=` de los allowedIncludes,
            // entre los que tampoco está `meeting` (sería redundante aquí).
            ->assertJsonPath('data.0.meeting', null)
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page', 'per_page']]);
    }

    public function test_los_compromisos_de_la_reunion_exigen_view_commitments(): void
    {
        [$user, $token] = $this->createTenantWithUser(['view_meetings'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/commitments")
            ->assertStatus(403);
    }

    public function test_los_compromisos_de_una_reunion_de_otro_tenant_dan_404(): void
    {
        $ajena = Meeting::factory()->forTenant(Tenant::factory()->create())->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$ajena->id}/commitments")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------

    private function crearLead(Tenant $tenant, string $cedula): Lead
    {
        return Lead::create([
            'tenant_id' => $tenant->id,
            'cedula' => $cedula,
            'nombre1' => 'Ana',
            'apellido1' => 'Restrepo',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $campos
     */
    private function plantillaCon(array $campos): MeetingTemplate
    {
        [$autor] = $this->createTenantWithUser([], $this->tenant);

        return MeetingTemplate::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $autor->id,
            'name' => 'Asamblea barrial',
            'description' => 'Formulario con preguntas extra',
            'fields' => $campos,
            'is_active' => true,
        ]);
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

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
 * El flujo de asistencia frente a la intención de negocio (Spec 0010, addendum).
 *
 * `.specify/context/domain-meetings-attendance.md` describe para qué existe el
 * módulo: caracterizar a quien asiste y medir **cuántos son nuevos**. Estas
 * pruebas contestan con evidencia ejecutable las cinco preguntas del addendum.
 *
 * Nacieron como caracterización: fijaban lo que el sistema NO hacía. Las specs
 * 0022 y 0026 implementaron buena parte, así que varias cambiaron de signo —que
 * es exactamente para lo que estaban escritas—. Lo que sigue sin ocurrir se fija
 * igual.
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
        // El check-in la consulta al crear una persona nueva (Spec 0022).
        Http::preventStrayRequests();
        Http::fake(['*pisami*' => Http::response('', 500)]);

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

    public function test_verify_document_ahora_reconoce_a_los_votantes_de_la_campania(): void
    {
        // Sus fuentes eran PISAMI (externa) y `leads`: quien ya estaba como
        // VOTANTE del tenant no se encontraba y tenía que volver a teclear sus
        // datos. Ahora `voters` es la primera fuente (Spec 0022).
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'telefono' => '3001112233',
        ]);

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO/verify-document?cedula=71000001')
            ->assertStatus(200)
            ->assertJsonPath('source', 'voters')
            ->assertJsonPath('data.nombres', 'Ana María')
            ->assertJsonPath('data.telefono', '3001112233');
    }

    public function test_una_asistencia_historica_sin_votante_sigue_sin_autocompletar(): void
    {
        // El lookup mira personas, no eventos: asistencia anterior a la 0022 que
        // se quedó sin `voter_id` no alimenta el autocompletado. Desde esta spec
        // ya no se generan casos así — todo check-in crea o liga su votante.
        MeetingAttendee::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'meeting_id' => $this->meeting->id,
            'cedula' => '99999999',
            'nombres' => 'Carmen',
            'apellidos' => 'Duque',
            'telefono' => '3007776655',
        ]);

        // El observer sí la convierte en votante al guardarla, así que para
        // reproducir el caso histórico hay que dejarla sin votante.
        Voter::withoutGlobalScope(TenantScope::class)->where('cedula', '99999999')->forceDelete();

        $this->getJson('/api/v1/meetings/public/QR-DOMINIO/verify-document?cedula=99999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_el_check_in_del_backend_completa_desde_el_votante(): void
    {
        // Antes el autocompletado vivía SOLO en el cliente y `checkIn` guardaba
        // literalmente lo que llegaba. Ahora el servidor busca a la persona en
        // `voters` y rellena lo que el formulario dejó en blanco, así que quien
        // llame a la API directamente recibe el mismo enriquecimiento
        // (Spec 0022).
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

        // Lo que la persona escribió sigue mandando.
        $this->assertSame('ana', $asistente->nombres);
        $this->assertSame('3001112233', $asistente->telefono, 'Se completó desde el votante existente.');
        $this->assertSame('ana@ejemplo.test', $asistente->email);
    }

    public function test_hueco_el_lookup_sobre_voters_existe_pero_es_privado(): void
    {
        // `searchByCedula` busca en `voters` pero exige `view_voters`, así que el
        // formulario público del QR nunca pudo usarlo. La 0022 no lo abre: lo
        // que hace es que el lookup del QR —ya acotado al tenant por la 0026—
        // consulte `voters` con su propia política de privacidad.
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

    public function test_el_check_in_crea_al_votante_y_lo_liga(): void
    {
        // Quien asiste pasa a ser votante del tenant: la asistencia alimenta la
        // base electoral en vez de quedar en una tabla aparte (Spec 0022).
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertSame($this->tenant->id, $votante->tenant_id);
        $this->assertSame('71000001', $votante->cedula);
        $this->assertSame(
            $votante->id,
            MeetingAttendee::withoutGlobalScope(TenantScope::class)->sole()->voter_id
        );
    }

    // ==================================================================
    // 2. Deduplicación por cédula — SÍ (Spec 0022)
    // ==================================================================

    public function test_un_segundo_check_in_del_mismo_documento_actualiza(): void
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

        // Una fila actualizada, no dos.
        $this->assertCount(1, $filas);
        $this->assertSame('3009998877', $filas[0]->telefono);

        // Y la reunión cuenta una persona.
        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertJsonPath('data.attendees_count', 1)
            ->assertJsonPath('data.checked_in_count', 1);
    }

    public function test_hay_identidad_de_persona_entre_reuniones(): void
    {
        $otra = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-DOMINIO-2']);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson("/api/v1/meetings/check-in/{$otra->qr_code}", $this->payload())->assertStatus(201);

        // Dos eventos de asistencia, una sola persona: `voter_id` es la clave
        // que antes no existía (Spec 0022).
        $filas = MeetingAttendee::withoutGlobalScope(TenantScope::class)->get();

        $this->assertCount(2, $filas);
        $this->assertNotSame($filas[0]->id, $filas[1]->id);
        $this->assertNotNull($filas[0]->voter_id);
        $this->assertSame($filas[0]->voter_id, $filas[1]->voter_id);
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

    public function test_el_backend_valida_extra_fields_contra_la_plantilla(): void
    {
        // Era el hallazgo F2: `CheckInRequest` validaba `extra_fields` como
        // `nullable|array` y nada más, así que la obligatoriedad la aplicaba solo
        // el formulario del frontend y llamar a la API la saltaba. Las dos
        // llamadas de abajo respondían 201. Cerrado por la Spec 0023; el detalle
        // vive en `CheckInCamposDinamicosTest`.
        $this->meeting->update(['template_id' => $this->plantillaCon([
            ['name' => 'profesion', 'label' => 'Profesión', 'type' => 'text', 'required' => true],
        ])->id]);

        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'extra_fields' => ['campo_que_la_plantilla_no_declara' => 'lo que sea'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        // Y sin el campo marcado como required, tampoco pasa.
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload(['cedula' => '71000002']))
            ->assertStatus(422)->assertJsonValidationErrors(['extra_fields']);

        $this->assertDatabaseCount('meeting_attendees', 0);
    }

    public function test_hueco_una_reunion_sin_plantilla_no_expone_campos_dinamicos(): void
    {
        $this->getJson('/api/v1/meetings/public/QR-DOMINIO')
            ->assertStatus(200)
            ->assertJsonPath('data.template', null);
    }

    // ==================================================================
    // 4. Nuevos vs recurrentes — SÍ (Spec 0022)
    // ==================================================================

    public function test_attendance_stats_distingue_nuevos_de_recurrentes(): void
    {
        // Escenario: Ana ya vino a otra reunión (recurrente) y Beatriz viene por
        // primera vez (nueva). 1 nueva, 1 recurrente.
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);

        Carbon::setTestNow('2026-07-06 09:00:00');
        $this->postJson("/api/v1/meetings/check-in/{$anterior->qr_code}", $this->payload())->assertStatus(201);

        Carbon::setTestNow('2026-08-06 09:00:00');
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'cedula' => '72000002', 'nombres' => 'Beatriz',
        ]))->assertStatus(201);

        [$user, $token] = $this->createTenantWithUser(['view_meetings', 'view_reports'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendance-stats")
            ->assertStatus(200)
            ->assertJsonPath('data.unique_attendees', 2)
            ->assertJsonPath('data.new_attendees', 1)
            ->assertJsonPath('data.recurring_attendees', 1);
    }

    public function test_los_demas_endpoints_siguen_contando_filas_no_personas(): void
    {
        // La métrica vive solo en `attendance-stats`. El resto sigue como
        // estaba, y conviene tenerlo escrito para no leer sus totales como si
        // fueran personas.
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);
        $this->postJson("/api/v1/meetings/check-in/{$anterior->qr_code}", $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/meetings/check-in/QR-DOMINIO', $this->payload([
            'cedula' => '72000002', 'nombres' => 'Beatriz',
        ]))->assertStatus(201);

        [$user, $token] = $this->createTenantWithUser(['view_meetings', 'view_reports'], $this->tenant);

        // a) Info pública de la reunión: cuenta filas, sin desglose.
        $publico = $this->getJson('/api/v1/meetings/public/QR-DOMINIO')->json('data');
        $this->assertSame(2, $publico['attendees_count']);
        $this->assertArrayNotHasKey('nuevos_count', $publico);

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

        // d) attendee-hierarchies/stats: cuenta cédulas distintas, pero de
        //    RELACIONES de jerarquía, no de asistencia.
        $jerarquia = $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/attendee-hierarchies/stats')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame(0, $jerarquia['unique_attendees'], 'Sin relaciones de jerarquía: 0, pese a haber 3 check-ins.');
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

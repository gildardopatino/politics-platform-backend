<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN de los asistentes a reuniones (Spec 0010).
 *
 * Los asistentes no tienen permisos propios: heredan los de meetings
 * (Spec 0005), porque son datos de la reunión.
 */
class MeetingAttendeeTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_index_lista_los_asistentes_de_la_reunion_con_sus_contadores(): void
    {
        $this->crearAsistente('71000001', true);
        $this->crearAsistente('71000002', false);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'cedula', 'nombres', 'apellidos', 'full_name', 'checked_in']],
                'meta' => ['total', 'current_page', 'last_page', 'checked_in_count', 'total_count'],
            ])
            ->assertJsonPath('meta.total_count', 2)
            ->assertJsonPath('meta.checked_in_count', 1);
    }

    public function test_index_pagina_de_50_en_50_por_defecto(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_filtra_por_checked_in(): void
    {
        $this->crearAsistente('71000001', true);
        $this->crearAsistente('71000002', false);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees?checked_in=true")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cedula', '71000001');

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees?checked_in=false")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cedula', '71000002');
    }

    public function test_index_exige_view_meetings(): void
    {
        $this->comoUsuarioCon([])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees")
            ->assertStatus(403);
    }

    public function test_index_de_una_reunion_de_otro_tenant_da_404(): void
    {
        $ajena = Meeting::factory()->forTenant(Tenant::factory()->create())->create();

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$ajena->id}/attendees")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_store_agrega_un_asistente_a_la_reunion(): void
    {
        $response = $this->comoUsuarioCon(['create_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", [
                'cedula' => '71000001',
                'nombres' => 'Ana',
                'apellidos' => 'Restrepo',
                'telefono' => '3001234567',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Attendee added successfully')
            ->assertJsonPath('data.cedula', '71000001')
            ->assertJsonPath('data.full_name', 'Ana Restrepo');

        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $this->meeting->id,
            'tenant_id' => $this->tenant->id,
            'cedula' => '71000001',
        ]);
    }

    public function test_rareza_la_respuesta_de_store_devuelve_checked_in_null_en_vez_de_false(): void
    {
        // `store` no toca `checked_in`, así que lo pone el DEFAULT de la
        // columna. Como el controller no recarga el modelo tras crearlo, el
        // atributo no está en memoria y sale null. En la base es false.
        // Es el mismo patrón que `data.status` al crear una reunión.
        $id = $this->comoUsuarioCon(['create_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", [
                'cedula' => '71000001',
                'nombres' => 'Ana',
                'apellidos' => 'Restrepo',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.checked_in', null)
            ->assertJsonPath('data.checked_in_at', null)
            ->json('data.id');

        $this->assertFalse(
            (bool) MeetingAttendee::withoutGlobalScope(TenantScope::class)->find($id)->checked_in
        );
    }

    public function test_store_valida_los_campos_obligatorios(): void
    {
        $this->comoUsuarioCon(['create_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cedula', 'nombres', 'apellidos']);
    }

    public function test_store_exige_create_meetings(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", [
                'cedula' => '71000001',
                'nombres' => 'Ana',
                'apellidos' => 'Restrepo',
            ])
            ->assertStatus(403);
    }

    public function test_rareza_store_admite_la_misma_cedula_dos_veces_en_la_misma_reunion(): void
    {
        $payload = ['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'];

        $this->comoUsuarioCon(['create_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $payload)
            ->assertStatus(201);
        $this->comoUsuarioCon(['create_meetings'])
            ->postJson("/api/v1/meetings/{$this->meeting->id}/attendees", $payload)
            ->assertStatus(201);

        $this->assertDatabaseCount('meeting_attendees', 2);
    }

    // ------------------------------------------------------------------
    // show / update / destroy
    // ------------------------------------------------------------------

    public function test_show_devuelve_el_asistente_con_su_reunion(): void
    {
        $asistente = $this->crearAsistente('71000001', false);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/attendees/{$asistente->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $asistente->id)
            ->assertJsonPath('data.meeting.id', $this->meeting->id);
    }

    public function test_update_modifica_los_datos_del_asistente(): void
    {
        $asistente = $this->crearAsistente('71000001', false);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/attendees/{$asistente->id}", ['telefono' => '3009999999'])
            ->assertStatus(200)
            ->assertJsonPath('data.telefono', '3009999999')
            ->assertJsonPath('message', 'Attendee updated successfully');
    }

    public function test_update_marcando_checked_in_sella_la_hora(): void
    {
        $asistente = $this->crearAsistente('71000001', false);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/attendees/{$asistente->id}", ['checked_in' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.checked_in', true);

        $this->assertSame(
            '2026-08-06 09:00:00',
            $asistente->fresh()->checked_in_at->toDateTimeString()
        );
    }

    public function test_rareza_desmarcar_checked_in_deja_la_hora_anterior(): void
    {
        // Solo se sella al pasar de false a true; al volver a false, el
        // `checked_in_at` se queda con la marca vieja.
        $asistente = $this->crearAsistente('71000001', true);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/attendees/{$asistente->id}", ['checked_in' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.checked_in', false);

        $this->assertNotNull($asistente->fresh()->checked_in_at);
    }

    public function test_destroy_borra_al_asistente_definitivamente(): void
    {
        $asistente = $this->crearAsistente('71000001', false);

        $this->comoUsuarioCon(['delete_meetings'])
            ->deleteJson("/api/v1/attendees/{$asistente->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Attendee deleted successfully');

        // La tabla no tiene softDeletes: el borrado es real.
        $this->assertDatabaseMissing('meeting_attendees', ['id' => $asistente->id]);
    }

    public function test_show_update_y_destroy_no_alcanzan_asistentes_de_otro_tenant(): void
    {
        $otro = Tenant::factory()->create();
        $ajena = Meeting::factory()->forTenant($otro)->create();
        $asistenteAjeno = $ajena->attendees()->create([
            'tenant_id' => $otro->id,
            'cedula' => '72000001',
            'nombres' => 'Beatriz',
            'apellidos' => 'Ospina',
        ]);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/attendees/{$asistenteAjeno->id}")
            ->assertStatus(404);

        $this->comoUsuarioCon(['edit_meetings'])
            ->putJson("/api/v1/attendees/{$asistenteAjeno->id}", ['nombres' => 'Secuestrada'])
            ->assertStatus(404);

        $this->comoUsuarioCon(['delete_meetings'])
            ->deleteJson("/api/v1/attendees/{$asistenteAjeno->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('meeting_attendees', [
            'id' => $asistenteAjeno->id,
            'nombres' => 'Beatriz',
        ]);
    }

    // ------------------------------------------------------------------
    // search / searchAll
    // ------------------------------------------------------------------

    public function test_search_sin_parametro_devuelve_400(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees/search")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Search parameter is required')
            ->assertJsonPath('data', []);
    }

    public function test_search_all_sin_parametro_devuelve_400(): void
    {
        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/attendees/search')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Search parameter is required');
    }

    public function test_search_exige_view_meetings(): void
    {
        $this->comoUsuarioCon([])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees/search?search=ana")
            ->assertStatus(403);
    }

    public function test_hallazgo_search_revienta_fuera_de_postgresql_por_usar_ilike(): void
    {
        // `search` y `searchAll` filtran con el operador `ilike`, exclusivo de
        // PostgreSQL. La Spec 0019 portó los otros casos (`ILIKE` en mayúsculas)
        // pero estos dos, en minúsculas, se le escaparon al grep. En la suite
        // (SQLite) responden 500.
        $this->crearAsistente('71000001', false);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendees/search?search=ana")
            ->assertStatus(500);

        $this->comoUsuarioCon(['view_meetings'])
            ->getJson('/api/v1/attendees/search?search=ana')
            ->assertStatus(500);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $permisos
     */
    private function comoUsuarioCon(array $permisos): static
    {
        [$user] = $this->createTenantWithUser($permisos, $this->tenant);

        return $this->actingAsTenantUser($user);
    }

    private function crearAsistente(string $cedula, bool $checkedIn): MeetingAttendee
    {
        return $this->meeting->attendees()->create([
            'tenant_id' => $this->tenant->id,
            'cedula' => $cedula,
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'checked_in' => $checkedIn,
            'checked_in_at' => $checkedIn ? now() : null,
        ]);
    }
}

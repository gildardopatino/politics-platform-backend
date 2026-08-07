<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuánta gente NUEVA llegó (Spec 0022, RF-5).
 *
 * Es la pregunta que justifica el módulo: distinguir crecimiento real de
 * "siempre van los mismos". Se cuenta por persona (cédula normalizada), no por
 * filas.
 *
 * **Nuevo** = esta reunión es la PRIMERA asistencia de esa persona en el tenant,
 * ordenando por `checked_in_at` y, a igualdad, por id. No es "no estaba en
 * `voters`": alguien puede llevar años en la base electoral y pisar su primera
 * reunión hoy, y eso es exactamente lo que se quiere medir.
 */
class AttendanceStatsTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*pisami*' => Http::response('', 500)]);

        Carbon::setTestNow('2026-08-06 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-STATS']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_separa_a_los_nuevos_de_los_recurrentes(): void
    {
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);

        // Hace un mes, Ana ya vino.
        Carbon::setTestNow('2026-07-06 09:00:00');
        $this->checkIn('71000001', 'Ana', $anterior->qr_code);

        // Hoy vuelve Ana y llega Beatriz por primera vez.
        Carbon::setTestNow('2026-08-06 09:00:00');
        $this->checkIn('71000001', 'Ana');
        $this->checkIn('72000002', 'Beatriz');

        $this->stats()
            ->assertStatus(200)
            ->assertJsonPath('data.meeting_id', $this->meeting->id)
            ->assertJsonPath('data.total_check_ins', 2)
            ->assertJsonPath('data.unique_attendees', 2)
            ->assertJsonPath('data.new_attendees', 1)
            ->assertJsonPath('data.recurring_attendees', 1);
    }

    public function test_en_la_reunion_donde_estreno_la_persona_cuenta_como_nueva(): void
    {
        // La misma Ana de la prueba anterior, mirada desde la reunión vieja: el
        // orden importa, no basta con "asistió a otra reunión".
        $anterior = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-ANTERIOR']);

        Carbon::setTestNow('2026-07-06 09:00:00');
        $this->checkIn('71000001', 'Ana', $anterior->qr_code);

        Carbon::setTestNow('2026-08-06 09:00:00');
        $this->checkIn('71000001', 'Ana');

        $this->stats($anterior)
            ->assertStatus(200)
            ->assertJsonPath('data.new_attendees', 1)
            ->assertJsonPath('data.recurring_attendees', 0);

        $this->stats()
            ->assertJsonPath('data.new_attendees', 0)
            ->assertJsonPath('data.recurring_attendees', 1);
    }

    public function test_dos_check_in_de_la_misma_persona_son_una_sola(): void
    {
        $this->checkIn('71000001', 'Ana');
        $this->checkIn('71.000.001', 'Ana');

        $this->stats()
            ->assertJsonPath('data.total_check_ins', 1)
            ->assertJsonPath('data.unique_attendees', 1)
            ->assertJsonPath('data.new_attendees', 1);
    }

    public function test_informa_cuanta_asistencia_quedo_ligada_a_una_persona(): void
    {
        $this->checkIn('71000001', 'Ana');
        $this->checkIn('72000002', 'Beatriz');

        $this->stats()->assertJsonPath('data.linked_to_voter', 2);
    }

    public function test_una_reunion_sin_asistencia_reporta_ceros(): void
    {
        $this->stats()
            ->assertStatus(200)
            ->assertJsonPath('data.total_check_ins', 0)
            ->assertJsonPath('data.unique_attendees', 0)
            ->assertJsonPath('data.new_attendees', 0)
            ->assertJsonPath('data.recurring_attendees', 0);
    }

    // ------------------------------------------------------------------
    // Aislamiento y permisos
    // ------------------------------------------------------------------

    public function test_la_asistencia_de_otra_campania_no_vuelve_recurrente_a_nadie(): void
    {
        $otro = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otro)->create(['qr_code' => 'QR-AJENO']);

        // La misma cédula asistió antes, pero en otra campaña: es otra persona.
        Carbon::setTestNow('2026-07-06 09:00:00');
        $this->checkIn('71000001', 'Ana', $reunionAjena->qr_code);

        Carbon::setTestNow('2026-08-06 09:00:00');
        $this->checkIn('71000001', 'Ana');

        $this->stats()
            ->assertJsonPath('data.unique_attendees', 1)
            ->assertJsonPath('data.new_attendees', 1)
            ->assertJsonPath('data.recurring_attendees', 0);
    }

    public function test_no_se_pueden_ver_las_estadisticas_de_una_reunion_ajena(): void
    {
        $otro = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otro)->create();

        [$user, $token] = $this->createTenantWithUser(['view_meetings'], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$reunionAjena->id}/attendance-stats")
            ->assertStatus(404);
    }

    public function test_exige_permiso_de_reuniones(): void
    {
        [$user, $token] = $this->createTenantWithUser([], $this->tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson("/api/v1/meetings/{$this->meeting->id}/attendance-stats")
            ->assertStatus(403);
    }

    public function test_sin_sesion_no_hay_estadisticas(): void
    {
        $this->flushHeaders();

        $this->getJson("/api/v1/meetings/{$this->meeting->id}/attendance-stats")
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------

    private function checkIn(string $cedula, string $nombres, string $qr = 'QR-STATS'): void
    {
        $this->flushHeaders();

        $this->postJson("/api/v1/meetings/check-in/{$qr}", [
            'cedula' => $cedula,
            'nombres' => $nombres,
            'apellidos' => 'Apellido',
        ])->assertStatus(201);
    }

    private function stats(?Meeting $meeting = null)
    {
        [$user, $token] = $this->createTenantWithUser(['view_meetings'], $this->tenant);

        return $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/meetings/'.($meeting ?? $this->meeting)->id.'/attendance-stats');
    }
}

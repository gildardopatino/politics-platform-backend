<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Check-in por persona, no por evento (Spec 0022).
 *
 * Antes, cada check-in era una fila suelta: la cédula era texto, no había con
 * qué deduplicar y quien ya estaba en `voters` volvía a teclear sus datos. Ahora
 * `AttendanceService` normaliza la cédula, busca o crea el Votante **dentro del
 * tenant de la reunión**, liga al asistente y completa lo que falte.
 *
 * El QR es lo que fija el tenant (Spec 0026): nada de esto puede alcanzar datos
 * de otra campaña.
 */
class AttendanceCheckInTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    /**
     * Lo que responde el recurso en línea. Por defecto, caído: la mayoría de
     * los casos no dependen de él. Los stubs de `Http::fake` se acumulan y gana
     * el primero registrado, así que se indirecciona por esta propiedad en vez
     * de volver a llamar a `fake()` dentro de cada prueba.
     */
    private mixed $recursoEnLinea;

    protected function setUp(): void
    {
        parent::setUp();

        // El recurso en línea es una API externa: la suite no sale a la red.
        Http::preventStrayRequests();
        $this->recursoEnLinea = Http::response('', 500);
        Http::fake(['*pisami*' => fn () => $this->recursoEnLinea]);

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0022']);
    }

    // ------------------------------------------------------------------
    // Persona ya conocida
    // ------------------------------------------------------------------

    public function test_liga_al_votante_existente_y_completa_los_datos_que_faltan(): void
    {
        $ana = Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'telefono' => '3001112233',
            'email' => 'ana@ejemplo.test',
        ]);

        // El formulario solo trae lo mínimo.
        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'])
            ->assertStatus(201);

        $asistente = $this->asistentes()->sole();

        $this->assertSame($ana->id, $asistente->voter_id, 'El asistente queda ligado a la persona.');
        $this->assertSame('3001112233', $asistente->telefono, 'Se completó desde el votante.');
        $this->assertSame('ana@ejemplo.test', $asistente->email);

        // Lo que la persona escribió manda sobre lo que había en la base.
        $this->assertSame('Ana', $asistente->nombres);

        // No se duplicó la persona.
        $this->assertSame(1, Voter::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_lo_que_trae_el_formulario_no_se_pisa_con_lo_del_votante(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'telefono' => '3001112233',
        ]);

        $this->checkIn([
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'telefono' => '3009998877',
        ])->assertStatus(201);

        $this->assertSame('3009998877', $this->asistentes()->sole()->telefono);
    }

    public function test_la_cedula_se_normaliza_antes_de_buscar_a_la_persona(): void
    {
        $ana = Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001']);

        $this->checkIn(['cedula' => ' 71.000.001 ', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'])
            ->assertStatus(201);

        $asistente = $this->asistentes()->sole();

        $this->assertSame($ana->id, $asistente->voter_id, '71.000.001 y 71000001 son la misma persona.');
        $this->assertSame('71000001', $asistente->cedula, 'Se guarda normalizada.');
    }

    // ------------------------------------------------------------------
    // Deduplicación
    // ------------------------------------------------------------------

    public function test_un_segundo_check_in_de_la_misma_cedula_actualiza_en_vez_de_duplicar(): void
    {
        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo', 'telefono' => '3001112233'])
            ->assertStatus(201);

        $this->checkIn(['cedula' => '71.000.001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo', 'telefono' => '3009998877'])
            ->assertStatus(201);

        $asistente = $this->asistentes()->sole();

        $this->assertSame('3009998877', $asistente->telefono, 'Gana el dato más reciente.');

        // Y la reunión cuenta una persona, no dos.
        $this->getJson('/api/v1/meetings/public/QR-0022')
            ->assertJsonPath('data.attendees_count', 1)
            ->assertJsonPath('data.checked_in_count', 1);
    }

    public function test_la_misma_persona_en_dos_reuniones_son_dos_asistencias_de_un_solo_votante(): void
    {
        $otra = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0022-B']);

        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'])->assertStatus(201);
        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'], $otra->qr_code)
            ->assertStatus(201);

        $asistencias = $this->asistentes()->get();

        $this->assertCount(2, $asistencias, 'Dos eventos de asistencia.');
        $this->assertSame(1, $asistencias->pluck('voter_id')->unique()->count(), 'Una sola persona.');
        $this->assertNotNull($asistencias->first()->voter_id);
    }

    // ------------------------------------------------------------------
    // Persona nueva
    // ------------------------------------------------------------------

    public function test_una_cedula_desconocida_crea_al_votante_y_lo_liga(): void
    {
        $this->checkIn([
            'cedula' => '72000002',
            'nombres' => 'Beatriz',
            'apellidos' => 'Salazar',
            'telefono' => '3005554433',
        ])->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertSame($this->tenant->id, $votante->tenant_id);
        $this->assertSame('72000002', $votante->cedula);
        $this->assertSame('Beatriz', $votante->nombres);
        $this->assertSame('3005554433', $votante->telefono);
        $this->assertSame($this->meeting->id, $votante->meeting_id, 'Queda la reunión donde se registró.');

        $this->assertSame($votante->id, $this->asistentes()->sole()->voter_id);
    }

    public function test_el_recurso_en_linea_completa_lo_que_el_formulario_no_pidio(): void
    {
        $this->recursoEnLinea = Http::response($this->respuestaPisami([
            'PRIMER_NOMBRE' => 'BEATRIZ',
            'PRIMER_APELLIDO' => 'SALAZAR',
            'TEL_MOVIL_NOTIFICACION' => '3005554433',
            'EMAIL' => 'beatriz@ejemplo.test',
        ]));

        $this->checkIn(['cedula' => '72000002', 'nombres' => 'Beatriz', 'apellidos' => 'Salazar'])
            ->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertSame('3005554433', $votante->telefono);
        $this->assertSame('beatriz@ejemplo.test', $votante->email);
        // Lo que la persona escribió sigue mandando.
        $this->assertSame('Beatriz', $votante->nombres);
    }

    public function test_si_el_recurso_en_linea_falla_se_crea_el_votante_con_lo_del_formulario(): void
    {
        $this->recursoEnLinea = Http::response('', 500);

        $this->checkIn(['cedula' => '72000002', 'nombres' => 'Beatriz', 'apellidos' => 'Salazar'])
            ->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertSame('Beatriz', $votante->nombres);
        $this->assertSame($votante->id, $this->asistentes()->sole()->voter_id);
    }

    // ------------------------------------------------------------------
    // Aislamiento entre campañas
    // ------------------------------------------------------------------

    public function test_no_se_liga_a_un_votante_de_otro_tenant(): void
    {
        $otroTenant = Tenant::factory()->create();
        $ajena = Voter::factory()->forTenant($otroTenant)->create([
            'cedula' => '71000001',
            'telefono' => '3001112233',
            'email' => 'ana@otra-campania.test',
        ]);

        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'])
            ->assertStatus(201);

        $asistente = $this->asistentes()->sole();

        $this->assertNotSame($ajena->id, $asistente->voter_id, 'La misma cédula en otra campaña es otra persona.');
        $this->assertNull($asistente->telefono, 'Ni un dato de la otra campaña se filtró al asistente.');
        $this->assertNull($asistente->email);

        // Se creó un votante propio del tenant del QR.
        $propio = Voter::withoutGlobalScope(TenantScope::class)->findOrFail($asistente->voter_id);
        $this->assertSame($this->tenant->id, $propio->tenant_id);
        $this->assertSame($this->tenant->id, $asistente->tenant_id);
    }

    public function test_el_check_in_no_toca_la_asistencia_de_otra_campania(): void
    {
        $otroTenant = Tenant::factory()->create();
        $reunionAjena = Meeting::factory()->forTenant($otroTenant)->create(['qr_code' => 'QR-AJENO']);

        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'], 'QR-AJENO')
            ->assertStatus(201);
        $this->checkIn(['cedula' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Restrepo'])
            ->assertStatus(201);

        $todas = MeetingAttendee::withoutGlobalScope(TenantScope::class)->get();

        $this->assertCount(2, $todas, 'Cada campaña registró la suya.');
        $this->assertSame(
            2,
            $todas->pluck('voter_id')->unique()->count(),
            'Dos personas distintas: una por tenant.'
        );
        $this->assertSame($otroTenant->id, $todas->firstWhere('meeting_id', $reunionAjena->id)->tenant_id);
    }

    // ------------------------------------------------------------------
    // La asistencia alimenta la base electoral
    // ------------------------------------------------------------------

    public function test_completa_los_huecos_del_votante_pero_no_pisa_lo_que_ya_tenia(): void
    {
        $ana = Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001',
            'telefono' => '3001112233',
            'email' => null,
        ]);

        $this->checkIn([
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'telefono' => '3009998877',
            'email' => 'ana@ejemplo.test',
        ])->assertStatus(201);

        $ana->refresh();

        $this->assertSame('ana@ejemplo.test', $ana->email, 'El hueco se llena.');
        $this->assertSame('3001112233', $ana->telefono, 'Un formulario público no reescribe lo ya curado.');
        $this->assertTrue($ana->has_multiple_records, 'Queda marcado el conflicto para revisión.');
    }

    // ------------------------------------------------------------------

    private function checkIn(array $datos, string $qr = 'QR-0022')
    {
        return $this->postJson("/api/v1/meetings/check-in/{$qr}", $datos);
    }

    private function asistentes()
    {
        return MeetingAttendee::withoutGlobalScope(TenantScope::class)->orderBy('id');
    }

    /**
     * PISAMI no responde JSON sino JavaScript que asigna valores a un
     * formulario. `PisamiService` lo parsea con expresiones regulares.
     */
    private function respuestaPisami(array $campos): string
    {
        $lineas = ['<script>'];
        foreach ($campos as $campo => $valor) {
            $lineas[] = "parent.document.f_pqr.{$campo}.value=\"{$valor}\";";
        }
        $lineas[] = '</script>';

        return implode("\n", $lineas);
    }
}

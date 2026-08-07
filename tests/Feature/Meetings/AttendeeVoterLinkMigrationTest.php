<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La columna que liga asistencia y persona (Spec 0022, Fase 0).
 *
 * El backfill no se puede probar corriendo la migración —`RefreshDatabase` ya
 * la aplicó sobre una base vacía—, así que se ejercita su lógica sobre datos
 * creados después: mismas reglas de emparejamiento, misma normalización.
 */
class AttendeeVoterLinkMigrationTest extends TestCase
{
    public function test_la_columna_existe_es_nullable_y_apunta_a_voters(): void
    {
        $this->assertTrue(Schema::hasColumn('meeting_attendees', 'voter_id'));

        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create();

        // Nullable a nivel de esquema: la asistencia histórica de alguien que
        // nunca entró a `voters` sigue siendo una fila válida.
        $asistente = $this->asistenteSinVotante($tenant, $meeting, '71000001');

        $this->assertNull($asistente->voter_id);
    }

    public function test_la_relacion_voter_resuelve_a_la_persona(): void
    {
        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create();
        $votante = Voter::factory()->forTenant($tenant)->create(['cedula' => '71000001']);

        $asistente = $meeting->attendees()->create([
            'tenant_id' => $tenant->id,
            'voter_id' => $votante->id,
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ]);

        $this->assertTrue($asistente->voter->is($votante));
    }

    public function test_borrar_al_votante_no_borra_la_asistencia(): void
    {
        // `nullOnDelete`: la reunión ocurrió, la asistencia es un hecho
        // histórico. Lo que se pierde es el vínculo con la persona.
        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create();
        $votante = Voter::factory()->forTenant($tenant)->create(['cedula' => '71000001']);

        $asistente = $meeting->attendees()->create([
            'tenant_id' => $tenant->id,
            'voter_id' => $votante->id,
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ]);

        $votante->forceDelete();

        $this->assertNull($asistente->fresh()->voter_id);
        $this->assertNotNull($asistente->fresh());
    }

    public function test_el_backfill_liga_por_cedula_normalizada_dentro_del_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $ana = Voter::factory()->forTenant($tenantA)->create(['cedula' => '71000001']);
        // Misma cédula en otro tenant: el backfill NO debe cruzarla.
        Voter::factory()->forTenant($tenantB)->create(['cedula' => '71000001']);

        $reunionA = Meeting::factory()->forTenant($tenantA)->create();
        $reunionB = Meeting::factory()->forTenant($tenantB)->create();

        // Cédula con puntos: el histórico se capturó a mano.
        $conPuntos = $this->asistenteSinVotante($tenantA, $reunionA, '71.000.001');
        $sinVotante = $this->asistenteSinVotante($tenantA, $reunionA, '99999999');
        $deOtroTenant = $this->asistenteSinVotante($tenantB, $reunionB, '71000001');

        $this->rellenarPorCedula();

        $this->assertSame($ana->id, $conPuntos->fresh()->voter_id, 'La cédula con puntos es la misma persona.');
        $this->assertNull($sinVotante->fresh()->voter_id, 'Sin votante no se inventa uno.');
        $this->assertNotSame(
            $ana->id,
            $deOtroTenant->fresh()->voter_id,
            'La misma cédula en otro tenant es otra persona.'
        );
    }

    public function test_el_backfill_es_idempotente(): void
    {
        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create();
        $ana = Voter::factory()->forTenant($tenant)->create(['cedula' => '71000001']);
        $asistente = $this->asistenteSinVotante($tenant, $meeting, '71000001');

        $this->rellenarPorCedula();
        $primero = $asistente->fresh()->voter_id;

        $this->rellenarPorCedula();

        $this->assertSame($ana->id, $primero);
        $this->assertSame($primero, $asistente->fresh()->voter_id);
    }

    /**
     * Inserta por debajo del modelo a propósito: el backfill corre sobre filas
     * que ya estaban en la tabla antes de esta spec, sin observers ni eventos.
     */
    private function asistenteSinVotante(Tenant $tenant, Meeting $meeting, string $cedula): MeetingAttendee
    {
        $id = DB::table('meeting_attendees')->insertGetId([
            'tenant_id' => $tenant->id,
            'meeting_id' => $meeting->id,
            'cedula' => $cedula,
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MeetingAttendee::withoutGlobalScope(TenantScope::class)->findOrFail($id);
    }

    /**
     * Réplica del relleno de la migración
     * `2026_08_06_120000_add_voter_id_to_meeting_attendees_table`.
     */
    private function rellenarPorCedula(): void
    {
        $normalizar = fn (?string $cedula) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $cedula) ?? '');

        DB::table('meeting_attendees')
            ->select('id', 'tenant_id', 'cedula')
            ->whereNull('voter_id')
            ->whereNotNull('cedula')
            ->orderBy('id')
            ->chunkById(500, function ($filas) use ($normalizar) {
                foreach ($filas as $fila) {
                    $voterId = DB::table('voters')
                        ->where('tenant_id', $fila->tenant_id)
                        ->whereNull('deleted_at')
                        ->get(['id', 'cedula'])
                        ->first(fn ($voter) => $normalizar($voter->cedula) === $normalizar($fila->cedula))?->id;

                    if ($voterId) {
                        DB::table('meeting_attendees')->where('id', $fila->id)->update(['voter_id' => $voterId]);
                    }
                }
            });
    }
}

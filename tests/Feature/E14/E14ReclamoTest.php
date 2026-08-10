<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\E14\E14ColaService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El reclamo de la cola (Spec 0071).
 *
 * Es el punto donde puede doler de verdad: si dos workers toman la misma acta,
 * se paga dos veces la lectura y, peor, dos procesos escriben sobre la misma
 * fila. Aquí se prueba que el motor decide un único ganador.
 */
class E14ReclamoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14], ?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * @return array<int, E14Acta>
     */
    private function pendientes(Tenant $tenant, int $cuantas, string $tipo = 'alcaldia'): array
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => ucfirst($tipo),
        ]);

        $actas = [];

        foreach (range(1, $cuantas) as $n) {
            $hash = hash('sha256', "{$tenant->id}-{$tipo}-{$n}");

            Storage::disk(config('e14.disk'))->put("e14/{$tenant->id}/{$hash}.pdf", '%PDF-1.4');

            $actas[] = E14Acta::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'electoral_event_id' => $evento->id,
                'tipo' => $tipo,
                'archivo_nombre' => "acta-{$n}.pdf",
                'archivo_hash' => $hash,
                'archivo_path' => "e14/{$tenant->id}/{$hash}.pdf",
                'estado' => E14Acta::ESTADO_PENDIENTE,
            ]);
        }

        return $actas;
    }

    // ------------------------------------------------------------- atomicidad

    public function test_dos_workers_con_la_misma_acta_en_la_mano_solo_uno_la_toma(): void
    {
        $tenant = Tenant::factory()->create();
        [$acta] = $this->pendientes($tenant, 1);

        $cola = app(E14ColaService::class);

        // Esta es la carrera de verdad: los dos ya eligieron la misma candidata
        // y van a marcarla. El `UPDATE ... WHERE estado = 'pendiente'` es una
        // sola sentencia, así que el motor decide y el segundo se queda sin
        // filas afectadas.
        $this->assertTrue($cola->tomar($acta), 'El primero debe llevársela.');
        $this->assertFalse($cola->tomar($acta), 'El segundo no puede llevarse la misma.');

        $this->assertSame(1, $acta->fresh()->intentos, 'Solo se cuenta el reclamo que ganó.');
    }

    public function test_cada_llamada_entrega_un_acta_distinta_y_luego_se_acaban(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 3);

        $entregadas = [];

        foreach (range(1, 3) as $ignorado) {
            $entregadas[] = $this->postJson('/api/v1/e14/actas/siguiente')
                ->assertStatus(200)
                ->json('data.id');
        }

        $this->assertCount(3, array_unique($entregadas), 'Ninguna acta puede entregarse dos veces.');

        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);
    }

    public function test_reclamar_marca_el_acta_como_procesando(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $this->postJson('/api/v1/e14/actas/siguiente')
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesando')
            ->assertJsonPath('data.intentos', 1);

        $this->assertNotNull(
            E14Acta::withoutGlobalScope(TenantScope::class)->first()->claimed_at
        );
    }

    public function test_un_acta_solo_cargada_no_se_reclama(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        E14Acta::withoutGlobalScope(TenantScope::class)->update(['estado' => 'cargada']);

        // Sin la orden de procesar no hay cola: subir no es encolar.
        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);
    }

    public function test_el_worker_puede_pedir_solo_los_tipos_que_sabe_leer(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1, 'concejo');
        $this->pendientes($tenant, 1, 'alcaldia');

        // Mientras no exista el parser de corporación (0067), el worker pide
        // solo uninominales y las de concejo se quedan en la cola sin estorbar.
        $this->postJson('/api/v1/e14/actas/siguiente', ['tipos' => ['alcaldia', 'gobernacion']])
            ->assertStatus(200)
            ->assertJsonPath('data.tipo', 'alcaldia');

        $this->postJson('/api/v1/e14/actas/siguiente', ['tipos' => ['alcaldia']])->assertStatus(204);

        $this->assertSame(
            'pendiente',
            E14Acta::withoutGlobalScope(TenantScope::class)->where('tipo', 'concejo')->first()->estado
        );
    }

    public function test_un_worker_no_alcanza_la_cola_de_otra_campana(): void
    {
        $ajena = Tenant::factory()->create();
        $this->pendientes($ajena, 2);

        $this->operador();

        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);

        $this->assertSame(
            2,
            E14Acta::withoutGlobalScope(TenantScope::class)->where('estado', 'pendiente')->count()
        );
    }

    // ---------------------------------------------------- reencolar colgadas

    public function test_un_acta_abandonada_por_un_worker_caido_vuelve_a_la_cola(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(200);
        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);

        // El worker se cayó a mitad de la lectura y nadie devolvió el acta.
        E14Acta::withoutGlobalScope(TenantScope::class)
            ->update(['claimed_at' => now()->subMinutes(config('e14.claim_timeout_minutes') + 1)]);

        $this->postJson('/api/v1/e14/actas/siguiente')
            ->assertStatus(200)
            ->assertJsonPath('data.intentos', 2);
    }

    public function test_un_acta_que_tumba_al_worker_acaba_en_revision_y_no_bloquea_la_cola(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $maximo = (int) config('e14.max_intentos');

        // Se reclama y se abandona tantas veces como el tope permite.
        foreach (range(1, $maximo) as $ignorado) {
            $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(200);
            E14Acta::withoutGlobalScope(TenantScope::class)
                ->update(['claimed_at' => now()->subMinutes(config('e14.claim_timeout_minutes') + 1)]);
        }

        // A la siguiente ya no se reintenta: un PDF que hace caer al lector no
        // puede tumbarlo indefinidamente a costa del resto.
        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        $this->assertSame('revision_manual', $acta->estado);
        $this->assertStringContainsString('intentos', $acta->observacion);
    }

    public function test_un_acta_recien_reclamada_no_se_reencola(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(200);

        // Todavía dentro del plazo: el worker sigue trabajando en ella.
        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);

        $this->assertSame(
            'procesando',
            E14Acta::withoutGlobalScope(TenantScope::class)->first()->estado
        );
    }

    // ---------------------------------------------------------- URL firmada

    public function test_la_url_firmada_entrega_el_pdf(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $url = $this->postJson('/api/v1/e14/actas/siguiente')->json('archivo_url');

        $this->assertNotNull($url);

        // Sin cabecera de autenticación: la firma es la credencial.
        $this->flushHeaders()
            ->get($url)
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_una_url_manipulada_no_sirve(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 2);

        $primera = $this->postJson('/api/v1/e14/actas/siguiente')->json('data.id');
        $url = $this->postJson('/api/v1/e14/actas/siguiente')->json('archivo_url');

        // Cambiar el acta invalida la firma: no se puede pedir otra con el
        // permiso de esta.
        $manipulada = preg_replace('#/actas/\d+/archivo#', "/actas/{$primera}/archivo", $url);

        $this->flushHeaders()->get($manipulada)->assertStatus(403);
    }

    public function test_la_url_caduca(): void
    {
        $tenant = $this->operador();
        $this->pendientes($tenant, 1);

        $url = $this->postJson('/api/v1/e14/actas/siguiente')->json('archivo_url');

        $this->travel((int) config('e14.url_ttl_minutes') + 1)->minutes();

        $this->flushHeaders()->get($url)->assertStatus(403);
    }

    public function test_sin_firma_no_hay_pdf(): void
    {
        $tenant = $this->operador();
        [$acta] = $this->pendientes($tenant, 1);

        $this->flushHeaders()
            ->get("/api/v1/e14/actas/{$acta->id}/archivo?tenant={$tenant->id}")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- permisos

    public function test_reclamar_exige_permiso_de_escritura(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $this->pendientes($tenant, 1);

        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(403);

        $this->assertSame(
            'pendiente',
            E14Acta::withoutGlobalScope(TenantScope::class)->first()->estado
        );
    }
}

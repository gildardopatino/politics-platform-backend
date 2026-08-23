<?php

namespace Tests\Feature\Voters;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\TipoVotante;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El disparo automático de la consulta (Spec 0091, Parte B · RF-B4).
 *
 * n8n preguntaba «¿quién falta?» cada tanto; ahora el momento es el nacimiento
 * del votante. El enganche está en **un solo sitio** —el observer del modelo—,
 * así que da igual por dónde nazca: check-in de reunión (Spec 0022), alta manual
 * o comando de sincronización.
 *
 * Y solo si nace **sin** puesto: quien llega con la ubicación tecleada no gasta
 * una consulta.
 */
class EncoladoConsultaPuestoTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // El recurso en línea del check-in (PISAMI) es una API externa.
        Http::fake(['*pisami*' => Http::response('', 500)]);

        Queue::fake();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');

        $this->tenant = Tenant::factory()->create();
    }

    private function operador(): void
    {
        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token);
    }

    private function assertEncolado(Voter $votante): void
    {
        Queue::assertPushed(
            ConsultarPuestoVotacionJob::class,
            fn (ConsultarPuestoVotacionJob $job) => $job->voterId === $votante->id
                && $job->tenantId === $votante->tenant_id
        );
    }

    // ============================================================= nace sin puesto

    public function test_un_votante_nuevo_sin_puesto_encola_la_consulta(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => null,
            'voting_place_id' => null,
        ]);

        $this->assertEncolado($votante);
    }

    public function test_el_check_in_de_una_reunion_encola_la_consulta(): void
    {
        Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0091']);

        $this->postJson('/api/v1/meetings/check-in/QR-0091', [
            'cedula' => '71000001',
            'nombres' => 'Ana',
            'apellidos' => 'Restrepo',
        ])->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertEncolado($votante);
    }

    public function test_el_alta_manual_sin_ubicacion_encola_la_consulta(): void
    {
        $this->operador();

        $this->postJson('/api/v1/voters', [
            'cedula' => '1110002222',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
        ])->assertStatus(201);

        $this->assertEncolado(Voter::withoutGlobalScope(TenantScope::class)->sole());
    }

    // ============================================================= nace con puesto

    public function test_un_votante_que_nace_con_puesto_no_encola_nada(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }

    public function test_el_alta_manual_con_ubicacion_tecleada_no_encola(): void
    {
        $this->operador();

        $this->postJson('/api/v1/voters', [
            'cedula' => '1110002222',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ])->assertStatus(201);

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }

    // ================================================================= no dispara

    public function test_editar_un_votante_no_encola_otra_consulta(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => null,
            'voting_place_id' => null,
        ]);

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 1);

        $votante->update(['telefono' => '3001112233']);

        // El disparo es el nacimiento, no cada escritura: si no, cada edición
        // volvería a pagar una consulta.
        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 1);
    }

    public function test_sin_servicio_configurado_no_se_encola_nada(): void
    {
        config()->set('services.registraduria.url', null);

        Voter::factory()->forTenant($this->tenant)->create([
            'departamento_votacion' => null,
            'voting_place_id' => null,
        ]);

        // Estado por defecto mientras el servicio Python no esté arriba: el flujo
        // queda apagado en vez de llenar la cola de trabajos que van a fallar.
        Queue::assertNothingPushed();
    }
}

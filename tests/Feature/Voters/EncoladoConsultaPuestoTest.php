<?php

namespace Tests\Feature\Voters;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\TipoVotante;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cuándo se encola la consulta de Registraduría (Spec 0091).
 *
 * El disparador es el que describe `VOTER_SYNC_SYSTEM.md`: cuando alguien
 * registra personas en una reunión, sus cédulas caen en `voters`. Ese es el
 * momento de resolver el puesto — **sin bloquear el check-in**, que es lo que
 * hace que vaya en cola y no en la petición.
 *
 * El encolado vive en un solo sitio (`despacharSiFalta`) y aquí se comprueba que
 * las dos vías por las que nace un votante pasan por él, y que la regla de coste
 * se respeta: quien nace **con** puesto no se consulta.
 */
class EncoladoConsultaPuestoTest extends TestCase
{
    private Tenant $tenant;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.registraduria.url', 'http://127.0.0.1:8100');

        // El recurso en línea del check-in (PISAMI) es una API externa.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 500)]);

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
        $this->meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0091']);

        TipoVotante::firstOrCreate(['descripcion' => 'Elector']);
    }

    private function checkIn(array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/meetings/check-in/QR-0091', $datos);
    }

    private function operador(): void
    {
        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token);
    }

    private function assertEncolaPara(Voter $votante): void
    {
        Queue::assertPushed(
            ConsultarPuestoVotacionJob::class,
            fn (ConsultarPuestoVotacionJob $job) => $job->voterId === $votante->id
                && $job->tenantId === $this->tenant->id
        );
    }

    // ------------------------------------------------- flujo de reunión (0022)

    public function test_un_votante_nacido_en_una_reunion_encola_la_consulta(): void
    {
        $this->checkIn([
            'cedula' => '14398737',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
        ])->assertStatus(201);

        $votante = Voter::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertEncolaPara($votante);
    }

    public function test_un_asistente_que_ya_era_votante_no_vuelve_a_consultarse(): void
    {
        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '14398737',
            'departamento_votacion' => 'TOLIMA',
        ]);

        // El check-in lo encuentra en vez de crearlo: no nace nadie, no se
        // consulta nada.
        $this->checkIn(['cedula' => '14398737', 'nombres' => 'ANA', 'apellidos' => 'GOMEZ'])
            ->assertStatus(201);

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }

    // -------------------------------------------------------------- alta manual

    public function test_el_alta_manual_sin_puesto_encola_la_consulta(): void
    {
        $this->operador();

        $this->postJson('/api/v1/voters', [
            'cedula' => '14398737',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
        ])->assertStatus(201);

        $this->assertEncolaPara(Voter::withoutGlobalScope(TenantScope::class)->sole());
    }

    public function test_el_alta_manual_con_puesto_tecleado_no_encola_nada(): void
    {
        $this->operador();

        // Quien captura ya sabe dónde vota esta persona: gastar una consulta
        // —que puede acabar pagando un reCAPTCHA— sería tirar el dinero.
        $this->postJson('/api/v1/voters', [
            'cedula' => '14398737',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ])->assertStatus(201);

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }

    public function test_tampoco_encola_si_el_formulario_resolvio_el_puesto_del_catalogo(): void
    {
        VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        $this->operador();

        $this->postJson('/api/v1/voters', [
            'cedula' => '14398737',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ])->assertStatus(201);

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }

    // ------------------------------------------------------ integración apagada

    public function test_sin_servicio_configurado_no_se_encola_nada(): void
    {
        // Estado por defecto (y el de la suite): llenar la cola de trabajos que
        // solo pueden fallar no ayuda a nadie.
        config()->set('services.registraduria.url', null);

        $this->checkIn(['cedula' => '14398737', 'nombres' => 'ANA', 'apellidos' => 'GOMEZ'])
            ->assertStatus(201);

        $this->assertSame(1, Voter::withoutGlobalScope(TenantScope::class)->count());
        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }
}

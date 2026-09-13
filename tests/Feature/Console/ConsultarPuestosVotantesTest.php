<?php

namespace Tests\Feature\Console;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `voters:consultar-puestos` — la red de seguridad del flujo de Registraduría
 * (Spec 0091).
 *
 * Reemplaza al `pendientes` de n8n. El disparo normal es automático al nacer el
 * votante, así que este comando existe para los votantes anteriores a la spec y
 * para los que se quedaron sin resolver mientras el servicio estuvo caído.
 *
 * `--limit` es lo que acota el gasto de 2Captcha por corrida, así que su
 * semántica —tope de **toda** la corrida, no por campaña— se fija aquí.
 */
class ConsultarPuestosVotantesTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.registraduria.url', 'http://127.0.0.1:8100');

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
    }

    private function votantes(int $cuantos, ?Tenant $tenant = null, array $atributos = []): void
    {
        Voter::factory()->count($cuantos)->forTenant($tenant ?? $this->tenant)->create($atributos);
    }

    public function test_encola_un_job_por_cada_votante_sin_puesto(): void
    {
        $this->votantes(3);

        $this->artisan('voters:consultar-puestos')->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 3);
    }

    public function test_no_encola_a_quien_ya_tiene_departamento(): void
    {
        $this->votantes(2);
        $this->votantes(2, atributos: ['departamento_votacion' => 'TOLIMA']);

        $this->artisan('voters:consultar-puestos')->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
    }

    public function test_no_encola_a_quien_ya_apunta_a_un_puesto_del_catalogo(): void
    {
        $puesto = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        $this->votantes(2);
        // Sin departamento pero con el puesto ya resuelto: ya sabemos dónde vota.
        $this->votantes(1, atributos: ['voting_place_id' => $puesto->id]);

        $this->artisan('voters:consultar-puestos')->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
    }

    public function test_el_limite_acota_la_corrida_entera(): void
    {
        $this->votantes(5);

        $this->artisan('voters:consultar-puestos', ['--limit' => 2])->assertSuccessful();

        // Cada consulta puede acabar pagando un reCAPTCHA: el tope es de gasto.
        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
    }

    public function test_el_limite_no_se_reparte_por_campana(): void
    {
        $otra = Tenant::factory()->create();

        $this->votantes(3);
        $this->votantes(3, $otra);

        $this->artisan('voters:consultar-puestos', ['--limit' => 4])->assertSuccessful();

        // Cuatro en total, no cuatro por campaña.
        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 4);
    }

    public function test_un_limite_mayor_que_los_pendientes_encola_solo_los_que_hay(): void
    {
        $this->votantes(2);

        $this->artisan('voters:consultar-puestos', ['--limit' => 10])->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
    }

    public function test_el_filtro_de_campana_no_encola_los_de_otra(): void
    {
        $otra = Tenant::factory()->create();

        $this->votantes(2);
        $this->votantes(3, $otra);

        $this->artisan('voters:consultar-puestos', ['--tenant' => $this->tenant->id])->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
        Queue::assertPushed(
            ConsultarPuestoVotacionJob::class,
            fn (ConsultarPuestoVotacionJob $job) => $job->tenantId === $this->tenant->id
        );
    }

    public function test_la_segunda_corrida_no_reencola_a_los_ya_resueltos(): void
    {
        $this->votantes(2);
        $resuelto = Voter::factory()->forTenant($this->tenant)->create();

        $this->artisan('voters:consultar-puestos')->assertSuccessful();
        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 3);

        // Entre una corrida y otra, uno de ellos resolvió.
        $resuelto->update(['departamento_votacion' => 'TOLIMA']);

        Queue::fake();

        $this->artisan('voters:consultar-puestos')->assertSuccessful();

        Queue::assertPushed(ConsultarPuestoVotacionJob::class, 2);
    }

    public function test_sin_servicio_configurado_el_comando_falla_en_vez_de_encolar(): void
    {
        config()->set('services.registraduria.url', null);
        $this->votantes(2);

        // Fallar es lo honesto: encolar en silencio trabajos que solo pueden
        // fallar deja al operador creyendo que el backfill corrió.
        $this->artisan('voters:consultar-puestos')->assertFailed();

        Queue::assertNotPushed(ConsultarPuestoVotacionJob::class);
    }
}

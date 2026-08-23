<?php

namespace Tests\Feature\Console;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Backfill de puestos de votación (Spec 0091, Parte B · RF-B5).
 *
 * Reemplaza al `GET .../registraduria/pendientes` de n8n: en vez de que un
 * tercero pida la lista, el comando recorre campaña por campaña a quien le falta
 * el puesto y **encola** un Job por cada uno.
 *
 * `--limit` no es una comodidad: 2Captcha se cobra por consulta y una campaña
 * con miles de votantes sin puesto puede costar dinero de verdad.
 */
class ConsultarPuestosVotantesTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');
    }

    private function sinPuesto(Tenant $tenant, int $cuantos = 1): void
    {
        Voter::factory()->count($cuantos)->forTenant($tenant)->create([
            'departamento_votacion' => null,
            'municipio_votacion' => null,
            'puesto_votacion' => null,
            'voting_place_id' => null,
        ]);
    }

    private function conPuesto(Tenant $tenant): Voter
    {
        return Voter::factory()->forTenant($tenant)->create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);
    }

    /** Los Jobs que encoló el comando, sin contar los del observer del alta. */
    private function encoladosPorElComando(callable $accion): array
    {
        $antes = [];
        Queue::pushed(ConsultarPuestoVotacionJob::class, function ($job) use (&$antes) {
            $antes[] = $job->voterId;

            return false;
        });

        $accion();

        $todos = [];
        Queue::pushed(ConsultarPuestoVotacionJob::class, function ($job) use (&$todos) {
            $todos[] = $job->voterId;

            return false;
        });

        return array_slice($todos, count($antes));
    }

    public function test_encola_una_consulta_por_votante_sin_puesto(): void
    {
        $tenant = Tenant::factory()->create();
        $this->sinPuesto($tenant, 3);

        $encolados = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos')->assertSuccessful()
        );

        $this->assertCount(3, $encolados);
    }

    public function test_no_encola_a_quien_ya_tiene_puesto(): void
    {
        $tenant = Tenant::factory()->create();
        $this->sinPuesto($tenant, 2);
        $this->conPuesto($tenant);
        $this->conPuesto($tenant);

        $encolados = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos')->assertSuccessful()
        );

        $this->assertCount(2, $encolados);
    }

    public function test_tampoco_encola_a_quien_ya_esta_ligado_al_catalogo(): void
    {
        $tenant = Tenant::factory()->create();
        $puesto = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        Voter::factory()->forTenant($tenant)->create([
            'departamento_votacion' => null,
            'voting_place_id' => $puesto->id,
        ]);

        $encolados = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos')->assertSuccessful()
        );

        $this->assertSame([], $encolados);
    }

    public function test_el_limite_acota_cuanto_se_gasta(): void
    {
        $tenant = Tenant::factory()->create();
        $this->sinPuesto($tenant, 5);

        $encolados = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos', ['--limit' => 2])->assertSuccessful()
        );

        $this->assertCount(2, $encolados);
    }

    public function test_la_segunda_corrida_no_re_encola_a_los_ya_resueltos(): void
    {
        $tenant = Tenant::factory()->create();
        $this->sinPuesto($tenant, 3);

        $primera = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos')->assertSuccessful()
        );
        $this->assertCount(3, $primera);

        // Dos de los tres ya se resolvieron entre corridas.
        Voter::whereIn('id', array_slice($primera, 0, 2))->update([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);

        $segunda = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos')->assertSuccessful()
        );

        $this->assertSame([$primera[2]], $segunda);
    }

    public function test_el_tenant_acota_la_corrida_a_una_campana(): void
    {
        $mia = Tenant::factory()->create();
        $ajena = Tenant::factory()->create();
        $this->sinPuesto($mia, 2);
        $this->sinPuesto($ajena, 3);

        $encolados = $this->encoladosPorElComando(
            fn () => $this->artisan('voters:consultar-puestos', ['--tenant' => $mia->id])->assertSuccessful()
        );

        $this->assertCount(2, $encolados);

        $delaMia = Voter::withoutGlobalScopes()->whereIn('id', $encolados)->pluck('tenant_id')->unique();
        $this->assertSame([$mia->id], $delaMia->values()->all());
    }

    public function test_cada_job_lleva_el_tenant_de_su_votante(): void
    {
        $una = Tenant::factory()->create();
        $otra = Tenant::factory()->create();
        $this->sinPuesto($una);
        $this->sinPuesto($otra);

        $this->artisan('voters:consultar-puestos')->assertSuccessful();

        // Sin esto el Job resolvería con los alias de la campaña equivocada
        // (Constitución, Art. III).
        Queue::assertPushed(ConsultarPuestoVotacionJob::class, function ($job) {
            $votante = Voter::withoutGlobalScopes()->find($job->voterId);

            return $votante !== null && $votante->tenant_id === $job->tenantId;
        });
    }

    public function test_sin_servicio_configurado_avisa_y_no_encola(): void
    {
        config()->set('services.registraduria.url', null);

        $tenant = Tenant::factory()->create();
        $this->sinPuesto($tenant, 2);

        $this->artisan('voters:consultar-puestos')
            ->expectsOutputToContain('REGISTRADURIA_SERVICE_URL')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_sin_campanas_que_recorrer_no_falla(): void
    {
        $this->artisan('voters:consultar-puestos', ['--tenant' => 999999])->assertSuccessful();

        Queue::assertNothingPushed();
    }
}

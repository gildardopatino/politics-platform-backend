<?php

namespace Tests\Feature\Voters;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\E14PuestoAlias;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Services\E14\PuestoResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * La consulta a Registraduría, en cola (Spec 0091, Parte B).
 *
 * Sustituye al *pull* de n8n: el Job sale a preguntar solo por quien **no** tiene
 * puesto, y se ejecuta sin petición HTTP detrás, así que tiene que enlazar
 * `current_tenant_id` él mismo o `TenantScope` no acota nada (Constitución,
 * Art. III).
 */
class ConsultarPuestoVotacionJobTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    private const CANONICO = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');

        $this->tenant = Tenant::factory()->create();
    }

    private function votante(?Tenant $tenant = null, array $atributos = []): Voter
    {
        return Voter::factory()->forTenant($tenant ?? $this->tenant)->create(array_replace([
            'cedula' => '14398737',
            'departamento_votacion' => null,
            'municipio_votacion' => null,
            'puesto_votacion' => null,
            'voting_place_id' => null,
        ], $atributos));
    }

    private function servicioResponde(array $cuerpo, int $codigo = 200): void
    {
        Http::fake([self::URL.'/api/consultar' => Http::response($cuerpo, $codigo)]);
    }

    private function encontrado(array $cambios = []): array
    {
        return [
            'estado' => 'encontrado',
            'via' => 'stealth',
            'datos' => [array_replace([
                'NUIP' => '14398737',
                'DEPARTAMENTO' => 'TOLIMA',
                'MUNICIPIO' => 'IBAGUE',
                'PUESTO' => self::CANONICO,
                'DIRECCION' => 'CALLE 60 CON CARRERA 5',
                'MESA' => '12',
            ], $cambios)],
        ];
    }

    private function correr(Voter $votante, ?Tenant $tenant = null): void
    {
        ConsultarPuestoVotacionJob::dispatchSync($votante->id, ($tenant ?? $this->tenant)->id);
    }

    private function fresco(Voter $votante): Voter
    {
        return Voter::withoutGlobalScope(TenantScope::class)->find($votante->id);
    }

    // ============================================================ el camino feliz

    public function test_escribe_el_puesto_que_devuelve_el_servicio(): void
    {
        $this->servicioResponde($this->encontrado());
        $votante = $this->votante();

        $this->correr($votante);

        $fresco = $this->fresco($votante);

        $this->assertSame('TOLIMA', $fresco->departamento_votacion);
        $this->assertSame('IBAGUE', $fresco->municipio_votacion);
        $this->assertSame(self::CANONICO, $fresco->puesto_votacion);
        $this->assertSame('12', (string) $fresco->mesa_votacion);
        $this->assertSame(VotingPlace::withoutGlobalScopes()->sole()->id, $fresco->voting_place_id);
    }

    public function test_casa_al_puesto_canonico_que_ya_existe(): void
    {
        $canonico = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ]);

        $this->servicioResponde($this->encontrado([
            'MUNICIPIO' => 'Ibagué',
            'PUESTO' => 'colegio san simón ',
        ]));

        $votante = $this->votante();
        $this->correr($votante);

        // Paridad con el viejo webhook (Spec 0075): mismo id canónico y el
        // catálogo no crece por una diferencia de tildes.
        $this->assertSame($canonico->id, $this->fresco($votante)->voting_place_id);
        $this->assertSame(1, VotingPlace::count());
    }

    // ============================================================== idempotencia

    public function test_no_consulta_a_quien_ya_tiene_departamento(): void
    {
        Http::fake();
        $votante = $this->votante(atributos: ['departamento_votacion' => 'TOLIMA']);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    public function test_no_consulta_a_quien_ya_tiene_puesto_del_catalogo(): void
    {
        Http::fake();
        $puesto = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ]);
        $votante = $this->votante(atributos: ['voting_place_id' => $puesto->id]);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    public function test_una_cedula_vacia_no_sale_a_la_red(): void
    {
        Http::fake();
        $votante = $this->votante(atributos: ['cedula' => '']);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    public function test_un_votante_que_ya_no_existe_no_revienta_la_cola(): void
    {
        Http::fake();
        $votante = $this->votante();
        $id = $votante->id;
        $votante->forceDelete();

        ConsultarPuestoVotacionJob::dispatchSync($id, $this->tenant->id);

        Http::assertNothingSent();
    }

    // ================================================== estados que no son datos

    public function test_no_encontrado_no_escribe_y_no_reintenta(): void
    {
        $this->servicioResponde(['estado' => 'no_encontrado', 'via' => 'stealth', 'datos' => []]);
        $votante = $this->votante();

        // No lanza: una cédula que no está en el censo no se arregla insistiendo.
        $this->correr($votante);

        $this->assertNull($this->fresco($votante)->departamento_votacion);
        $this->assertNull($this->fresco($votante)->voting_place_id);
    }

    public function test_un_fallo_del_servicio_hace_que_la_cola_reintente(): void
    {
        $this->servicioResponde(['estado' => 'captcha_fallido', 'via' => null, 'datos' => []], 502);
        $votante = $this->votante();

        $this->expectException(RuntimeException::class);

        try {
            $this->correr($votante);
        } finally {
            $this->assertNull($this->fresco($votante)->departamento_votacion);
        }
    }

    public function test_el_job_tiene_reintentos_acotados_y_backoff(): void
    {
        $job = new ConsultarPuestoVotacionJob(1, 1);

        $this->assertGreaterThan(1, $job->tries);
        $this->assertNotEmpty($job->backoff());
        // Un backoff creciente: el servicio puede estar reiniciándose.
        $this->assertSame(array_values($job->backoff()), $job->backoff());
    }

    // ========================================================= aislamiento (Art. III)

    public function test_enlaza_el_tenant_y_lo_deja_como_estaba(): void
    {
        $this->servicioResponde($this->encontrado());
        $votante = $this->votante();

        // La cola no trae petición: si el Job no enlaza, `TenantScope` no filtra.
        $this->assertFalse(app()->bound('current_tenant_id') && app('current_tenant_id') !== null);

        $this->correr($votante);

        $this->assertSame(self::CANONICO, $this->fresco($votante)->puesto_votacion);
        $this->assertNull(app()->bound('current_tenant_id') ? app('current_tenant_id') : null);
    }

    public function test_un_job_de_una_campana_no_toca_al_votante_de_otra(): void
    {
        Http::fake();
        $ajeno = Tenant::factory()->create();
        $votanteAjeno = $this->votante($ajeno);

        // El id existe, pero es de otra campaña: acotado por `TenantScope`, el
        // Job no lo encuentra y no consulta nada.
        ConsultarPuestoVotacionJob::dispatchSync($votanteAjeno->id, $this->tenant->id);

        Http::assertNothingSent();
        $this->assertNull($this->fresco($votanteAjeno)->departamento_votacion);
    }

    public function test_resuelve_con_los_alias_de_su_campana_y_no_con_los_de_otra(): void
    {
        $canonico = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ]);
        $variante = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COL. SAN SIMON',
        ]);

        // Solo esta campaña fusionó las dos grafías.
        E14PuestoAlias::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'clave' => PuestoResolver::clave('IBAGUE', 'COL. SAN SIMON'),
            'voting_place_id' => $canonico->id,
            'municipio' => 'IBAGUE',
            'puesto' => 'COL. SAN SIMON',
        ]);

        $this->servicioResponde($this->encontrado(['PUESTO' => 'COL. SAN SIMON']));

        $ajeno = Tenant::factory()->create();
        $votanteAjeno = $this->votante($ajeno);

        ConsultarPuestoVotacionJob::dispatchSync($votanteAjeno->id, $ajeno->id);

        // La fusión es del otro tenant: este votante va a la variante.
        $this->assertSame($variante->id, $this->fresco($votanteAjeno)->voting_place_id);
        $this->assertNotSame($canonico->id, $this->fresco($votanteAjeno)->voting_place_id);
    }

    public function test_la_cedula_no_viaja_en_el_nombre_de_la_cola(): void
    {
        // El Job serializa ids, no PII: lo que quede en `jobs`/`failed_jobs` no
        // puede llevar la cédula (Art. VII).
        $job = new ConsultarPuestoVotacionJob(7, 3);

        $this->assertStringNotContainsString('14398737', serialize($job));
    }
}

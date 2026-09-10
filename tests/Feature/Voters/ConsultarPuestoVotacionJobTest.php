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
 * El Job que consulta el puesto de votación (Spec 0091).
 *
 * Dos cosas se fijan aquí y las dos cuestan dinero o datos si se rompen:
 *
 * 1. **Idempotencia.** Cada consulta puede acabar pagando un reCAPTCHA, así que
 *    un votante que ya tiene puesto no se vuelve a consultar — venga el puesto de
 *    donde venga (un acta, una edición a mano, un Job gemelo de dos check-in
 *    simultáneos de la misma cédula).
 * 2. **Aislamiento (Art. III).** En la cola no hay petición que enlace
 *    `current_tenant_id`, así que lo enlaza el Job. Sin eso, `TenantScope` no
 *    filtraría y `PuestoResolver` resolvería con las fusiones de otra campaña.
 *
 * Sin red: `Http::fake` en todos los casos.
 */
class ConsultarPuestoVotacionJobTest extends TestCase
{
    private const URL = 'http://127.0.0.1:8100';

    private const CANONICO = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.registraduria.url', self::URL);
        config()->set('services.registraduria.token', 'token-de-prueba');

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function servicioResponde(array $cambios = [], int $estado = 200): void
    {
        Http::fake([
            self::URL.'/api/consultar' => Http::response(array_replace([
                'estado' => 'encontrado',
                'via' => 'stealth',
                'datos' => [[
                    'NUIP' => '14398737',
                    'DEPARTAMENTO' => 'TOLIMA',
                    'MUNICIPIO' => 'IBAGUE',
                    'PUESTO' => self::CANONICO,
                    'DIRECCION' => 'CALLE 60 CON CARRERA 5',
                    'MESA' => '12',
                ]],
            ], $cambios), $estado),
        ]);
    }

    private function votante(?Tenant $tenant = null, array $atributos = []): Voter
    {
        return Voter::factory()->forTenant($tenant ?? $this->tenant)->create($atributos);
    }

    private function correr(Voter $voter, ?Tenant $tenant = null): void
    {
        (new ConsultarPuestoVotacionJob($voter->id, ($tenant ?? $this->tenant)->id))
            ->handle(
                app(\App\Services\Registraduria\RegistraduriaClient::class),
                app(\App\Services\Registraduria\RegistraduriaSyncService::class),
            );
    }

    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ], $cambios));
    }

    private function fusionar(Tenant $tenant, string $municipio, string $puesto, VotingPlace $destino): void
    {
        E14PuestoAlias::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'clave' => PuestoResolver::clave($municipio, $puesto),
            'voting_place_id' => $destino->id,
            'municipio' => $municipio,
            'puesto' => $puesto,
        ]);
    }

    private function recargar(Voter $voter): Voter
    {
        return Voter::withoutGlobalScope(TenantScope::class)->find($voter->id);
    }

    // ------------------------------------------------------------ idempotencia

    public function test_no_consulta_si_el_votante_ya_tiene_departamento(): void
    {
        // `preventStrayRequests` sin `fake` convierte cualquier salida a la red en
        // un fallo de la prueba: es la aserción.
        $votante = $this->votante(atributos: ['departamento_votacion' => 'TOLIMA']);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    public function test_no_consulta_si_el_votante_ya_apunta_a_un_puesto(): void
    {
        $puesto = $this->puestoDelCatalogo();
        $votante = $this->votante(atributos: ['voting_place_id' => $puesto->id]);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    public function test_un_votante_que_ya_no_existe_no_rompe_nada(): void
    {
        $votante = $this->votante();
        $id = $votante->id;
        $votante->forceDelete();

        (new ConsultarPuestoVotacionJob($id, $this->tenant->id))->handle(
            app(\App\Services\Registraduria\RegistraduriaClient::class),
            app(\App\Services\Registraduria\RegistraduriaSyncService::class),
        );

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- resolución

    public function test_escribe_el_puesto_que_devuelve_la_registraduria(): void
    {
        $this->servicioResponde();
        $votante = $this->votante(atributos: ['cedula' => '14398737']);

        $this->correr($votante);

        $fresco = $this->recargar($votante);

        $this->assertSame('TOLIMA', $fresco->departamento_votacion);
        $this->assertSame(self::CANONICO, $fresco->puesto_votacion);
        $this->assertSame('12', $fresco->mesa_votacion);
        $this->assertSame(VotingPlace::sole()->id, $fresco->voting_place_id);
    }

    public function test_casa_al_puesto_canonico_del_catalogo_sin_duplicarlo(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $this->servicioResponde(['datos' => [[
            'DEPARTAMENTO' => 'TOLIMA',
            'MUNICIPIO' => 'Ibagué',
            'PUESTO' => 'colegio san simón ',
            'DIRECCION' => null,
            'MESA' => '3',
        ]]]);

        $votante = $this->votante();

        $this->correr($votante);

        // El acta apunta al canónico; si el votante apuntara a un duplicado, el
        // cruce por `voting_place_id` fallaría en silencio (Spec 0075).
        $this->assertSame($canonico->id, $this->recargar($votante)->voting_place_id);
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_no_encontrado_termina_bien_y_deja_al_votante_sin_puesto(): void
    {
        $this->servicioResponde(['estado' => 'no_encontrado', 'datos' => []]);
        $votante = $this->votante();

        // No lanza: reintentarlo sería pagar 2Captcha por volver a oír que la
        // Registraduría no tiene esa cédula.
        $this->correr($votante);

        $this->assertNull($this->recargar($votante)->departamento_votacion);
        $this->assertNull($this->recargar($votante)->voting_place_id);
    }

    public function test_un_fallo_del_servicio_lanza_para_que_la_cola_reintente(): void
    {
        $this->servicioResponde(['estado' => 'captcha_fallido', 'datos' => []], 502);
        $votante = $this->votante();

        $this->expectException(RuntimeException::class);

        $this->correr($votante);
    }

    public function test_el_mensaje_del_fallo_no_lleva_la_cedula(): void
    {
        $this->servicioResponde(['estado' => 'captcha_fallido', 'datos' => []], 502);
        $votante = $this->votante(atributos: ['cedula' => '14398737']);

        try {
            $this->correr($votante);
            $this->fail('Se esperaba una excepción del Job.');
        } catch (RuntimeException $e) {
            // Art. VII: la cédula es PII y las excepciones acaban en los logs.
            $this->assertStringNotContainsString('14398737', $e->getMessage());
            $this->assertStringContainsString('captcha_fallido', $e->getMessage());
        }
    }

    public function test_un_votante_sin_cedula_no_llega_a_consultarse(): void
    {
        $votante = $this->votante(atributos: ['cedula' => '']);

        $this->correr($votante);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- aislamiento

    public function test_el_job_de_una_campana_no_toca_al_votante_de_otra(): void
    {
        $this->servicioResponde();

        $ajeno = Tenant::factory()->create();
        $votanteAjeno = $this->votante($ajeno);

        // El Job dice tenant A, pero el votante es de B: dentro del ámbito de A
        // ese id sencillamente no existe.
        $this->correr($votanteAjeno, $this->tenant);

        Http::assertNothingSent();
        $this->assertNull($this->recargar($votanteAjeno)->departamento_votacion);
    }

    public function test_resuelve_con_los_alias_de_su_campana_y_no_con_los_de_otra(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $variante = $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);

        // Solo la campaña ajena fusionó las dos grafías. La nuestra no.
        $ajeno = Tenant::factory()->create();
        $this->fusionar($ajeno, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $this->servicioResponde(['datos' => [[
            'DEPARTAMENTO' => 'TOLIMA',
            'MUNICIPIO' => 'IBAGUE',
            'PUESTO' => 'COL. SAN SIMON',
            'DIRECCION' => null,
            'MESA' => '1',
        ]]]);

        $votante = $this->votante();

        $this->correr($votante);

        // Una fusión ajena no puede mover a nuestro votante de puesto.
        $this->assertSame($variante->id, $this->recargar($votante)->voting_place_id);
        $this->assertNotSame($canonico->id, $this->recargar($votante)->voting_place_id);
    }

    public function test_el_tenant_enlazado_se_deja_como_estaba(): void
    {
        $this->servicioResponde();
        $votante = $this->votante();

        app()->instance('current_tenant_id', 999);

        $this->correr($votante);

        // El worker es un proceso largo: dejar el tenant puesto convertiría el
        // ámbito de este trabajo en el del siguiente.
        $this->assertSame(999, app('current_tenant_id'));
    }
}

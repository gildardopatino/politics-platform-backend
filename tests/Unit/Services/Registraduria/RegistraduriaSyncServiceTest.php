<?php

namespace Tests\Unit\Services\Registraduria;

use App\Models\E14PuestoAlias;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Services\E14\PuestoResolver;
use App\Services\Registraduria\RegistraduriaSyncService;
use Tests\TestCase;

/**
 * Guardado de lo que devuelve Registraduría (Spec 0091, Parte B).
 *
 * Es la lógica que vivía dentro de `VoterController@actualizarRegistraduria`,
 * extraída para que la use la cola. Los invariantes son los de la Spec 0075 y no
 * cambian por mudarse de sitio: se resuelve al puesto **canónico** con el mismo
 * `PuestoResolver` del E-14 (alias del tenant → catálogo normalizado → alta), el
 * catálogo global no crece por una diferencia de tildes, y la dirección del
 * puesto solo se siembra si estaba vacía.
 */
class RegistraduriaSyncServiceTest extends TestCase
{
    private const CANONICO = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('current_tenant_id', $this->tenant->id);
    }

    private function servicio(): RegistraduriaSyncService
    {
        return app(RegistraduriaSyncService::class);
    }

    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ], $cambios));
    }

    private function votante(?Tenant $tenant = null): Voter
    {
        return Voter::factory()->forTenant($tenant ?? $this->tenant)->create([
            'departamento_votacion' => null,
            'municipio_votacion' => null,
            'puesto_votacion' => null,
            'voting_place_id' => null,
        ]);
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

    /** La fila tal como la devuelve el servicio Python (claves en mayúsculas). */
    private function filaDelServicio(array $cambios = []): array
    {
        return array_replace([
            'NUIP' => '14398737',
            'DEPARTAMENTO' => 'TOLIMA',
            'MUNICIPIO' => 'IBAGUE',
            'PUESTO' => self::CANONICO,
            'DIRECCION' => 'CALLE 60 CON CARRERA 5',
            'MESA' => '12',
        ], $cambios);
    }

    // ================================================== mapeo del contrato

    public function test_mapea_la_fila_del_servicio_a_los_campos_del_votante(): void
    {
        $datos = RegistraduriaSyncService::mapear($this->filaDelServicio());

        $this->assertSame([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'direccion_votacion' => 'CALLE 60 CON CARRERA 5',
            'mesa_votacion' => '12',
        ], $datos);
    }

    public function test_una_fila_sin_puesto_no_produce_datos(): void
    {
        $this->assertSame([], RegistraduriaSyncService::mapear($this->filaDelServicio(['PUESTO' => ''])));
        $this->assertSame([], RegistraduriaSyncService::mapear($this->filaDelServicio(['MUNICIPIO' => null])));
        $this->assertSame([], RegistraduriaSyncService::mapear([]));
    }

    // ======================================================== el guardado

    public function test_escribe_los_cinco_campos_y_el_puesto_resuelto(): void
    {
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear($this->filaDelServicio()));

        $fresco = $votante->fresh();

        $this->assertSame('TOLIMA', $fresco->departamento_votacion);
        $this->assertSame('IBAGUE', $fresco->municipio_votacion);
        $this->assertSame(self::CANONICO, $fresco->puesto_votacion);
        $this->assertSame('CALLE 60 CON CARRERA 5', $fresco->direccion_votacion);
        $this->assertSame('12', (string) $fresco->mesa_votacion);
        $this->assertSame(VotingPlace::sole()->id, $fresco->voting_place_id);
    }

    public function test_casa_al_canonico_aunque_llegue_otra_grafia(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear($this->filaDelServicio([
            'MUNICIPIO' => 'Ibagué',
            'PUESTO' => 'colegio san simón ',
        ])));

        $this->assertSame($canonico->id, $votante->fresh()->voting_place_id);
        // El catálogo global no crece por una diferencia de tildes (Spec 0075).
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_crea_el_renglon_con_departamento_cuando_no_existe(): void
    {
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear($this->filaDelServicio()));

        $puesto = VotingPlace::sole();

        $this->assertSame(self::CANONICO, $puesto->puesto_votacion);
        $this->assertSame('TOLIMA', $puesto->departamento_votacion);
        $this->assertSame($puesto->id, $votante->fresh()->voting_place_id);
    }

    public function test_respeta_la_fusion_que_hizo_la_campana(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $variante = $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);
        $this->fusionar($this->tenant, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear(
            $this->filaDelServicio(['PUESTO' => 'COL. SAN SIMON'])
        ));

        $this->assertSame($canonico->id, $votante->fresh()->voting_place_id);
        $this->assertNotSame($variante->id, $votante->fresh()->voting_place_id);
    }

    public function test_la_fusion_de_una_campana_no_resuelve_la_de_otra(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $variante = $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);
        $this->fusionar($this->tenant, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $ajeno = Tenant::factory()->create();
        $votanteAjeno = $this->votante($ajeno);

        // El servicio resuelve con los alias del tenant enlazado, no con los de
        // la campaña que fusionó (Constitución, Art. III).
        app()->instance('current_tenant_id', $ajeno->id);
        $this->servicio()->aplicar($votanteAjeno, RegistraduriaSyncService::mapear(
            $this->filaDelServicio(['PUESTO' => 'COL. SAN SIMON'])
        ));

        $suyo = Voter::withoutGlobalScope(TenantScope::class)->find($votanteAjeno->id);

        $this->assertSame($variante->id, $suyo->voting_place_id);
        $this->assertNotSame($canonico->id, $suyo->voting_place_id);
    }

    public function test_siembra_la_direccion_del_puesto_solo_si_estaba_vacia(): void
    {
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear($this->filaDelServicio([
            'DIRECCION' => 'CALLE 10 # 5-20',
        ])));

        $this->assertSame('CALLE 10 # 5-20', VotingPlace::sole()->direccion_votacion);

        // El catálogo es global: la dirección que ya tiene un puesto pudo ponerla
        // otra campaña o un acta, y no se pisa.
        $otro = $this->votante();
        $this->servicio()->aplicar($otro, RegistraduriaSyncService::mapear($this->filaDelServicio([
            'DIRECCION' => 'OTRA DIRECCION DISTINTA',
        ])));

        $this->assertSame('CALLE 10 # 5-20', VotingPlace::sole()->direccion_votacion);
    }

    public function test_aplicar_dos_veces_no_duplica_ni_cambia_el_id(): void
    {
        $votante = $this->votante();
        $datos = RegistraduriaSyncService::mapear($this->filaDelServicio());

        $this->servicio()->aplicar($votante, $datos);
        $primerId = $votante->fresh()->voting_place_id;

        $this->servicio()->aplicar($votante->fresh(), $datos);

        $this->assertSame($primerId, $votante->fresh()->voting_place_id);
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_una_mesa_con_letra_se_guarda_tal_cual(): void
    {
        // `voters.mesa_votacion` es `string(20)`. El webhook de n8n la validaba
        // como entero y rechazaba «12A» (known-issues); al retirarlo, esta ruta
        // guarda lo que diga el censo.
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, RegistraduriaSyncService::mapear($this->filaDelServicio([
            'MESA' => '12A',
        ])));

        $this->assertSame('12A', $votante->fresh()->mesa_votacion);
    }

    public function test_sin_datos_no_toca_al_votante(): void
    {
        $votante = $this->votante();

        $this->servicio()->aplicar($votante, []);

        $this->assertNull($votante->fresh()->departamento_votacion);
        $this->assertNull($votante->fresh()->voting_place_id);
        $this->assertSame(0, VotingPlace::count());
    }
}

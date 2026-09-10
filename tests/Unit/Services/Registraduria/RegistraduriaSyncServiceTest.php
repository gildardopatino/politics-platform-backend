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
 * El guardado del puesto que devuelve la Registraduría (Spec 0091).
 *
 * Estos casos son los de la Spec 0075 —los que probaba el webhook de n8n— vueltos
 * a fijar sobre el servicio que lo reemplaza: la ruta cambió, el invariante no.
 * Si el votante deja de resolver al puesto **canónico**, el cruce del E-14 vuelve
 * a fallar en silencio, que es el bug que la 0075 cerró.
 */
class RegistraduriaSyncServiceTest extends TestCase
{
    private const CANONICO = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // El Job enlaza el tenant antes de aplicar; aquí se hace a mano porque no
        // hay petición. Sin él, `PuestoResolver` no vería los alias de nadie.
        app()->instance('current_tenant_id', $this->tenant->id);
    }

    private function sync(): RegistraduriaSyncService
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
        return Voter::factory()->forTenant($tenant ?? $this->tenant)->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'direccion_votacion' => null,
            'mesa_votacion' => '5',
        ], $cambios);
    }

    /** Fusión decidida por la campaña: «este nombre va a este puesto». */
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

    public function test_casa_al_canonico_aunque_llegue_otra_grafia(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante();

        // La misma escuela con otra grafía: minúsculas, tilde y un espacio de más.
        $this->sync()->aplicar($votante, $this->datos([
            'municipio_votacion' => 'Ibagué',
            'puesto_votacion' => 'colegio san simón ',
        ]));

        $this->assertSame($canonico->id, $votante->fresh()->voting_place_id);
        // El catálogo global no crece por una diferencia de tildes.
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_crea_el_puesto_cuando_no_existe(): void
    {
        $votante = $this->votante();

        // Registraduría es el censo oficial: si el puesto no está en el catálogo,
        // lo que falta es el renglón. Misma regla que el acta.
        $this->sync()->aplicar($votante, $this->datos());

        $puesto = VotingPlace::sole();

        $this->assertSame(self::CANONICO, $puesto->puesto_votacion);
        $this->assertSame($puesto->id, $votante->fresh()->voting_place_id);
    }

    public function test_escribe_los_cinco_campos_del_votante(): void
    {
        $votante = $this->votante();

        $this->sync()->aplicar($votante, $this->datos([
            'direccion_votacion' => 'CALLE 60 CON CARRERA 5',
            'mesa_votacion' => '12',
        ]));

        $fresco = $votante->fresh();

        $this->assertSame('TOLIMA', $fresco->departamento_votacion);
        $this->assertSame('IBAGUE', $fresco->municipio_votacion);
        $this->assertSame(self::CANONICO, $fresco->puesto_votacion);
        $this->assertSame('CALLE 60 CON CARRERA 5', $fresco->direccion_votacion);
        $this->assertSame('12', $fresco->mesa_votacion);
        $this->assertNotNull($fresco->voting_place_id);
    }

    public function test_siembra_la_direccion_del_puesto_solo_si_estaba_vacia(): void
    {
        $votante = $this->votante();

        $this->sync()->aplicar($votante, $this->datos(['direccion_votacion' => 'CALLE 10 # 5-20']));

        $this->assertSame('CALLE 10 # 5-20', VotingPlace::sole()->direccion_votacion);

        // El catálogo es global: la dirección que ya tiene un puesto pudo ponerla
        // otra campaña o un acta, y no se pisa.
        $this->sync()->aplicar($this->votante(), $this->datos(['direccion_votacion' => 'OTRA DIRECCION']));

        $this->assertSame('CALLE 10 # 5-20', VotingPlace::sole()->direccion_votacion);
    }

    public function test_respeta_la_fusion_que_hizo_la_campana(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $variante = $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);
        $this->fusionar($this->tenant, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $votante = $this->votante();

        // Alguien ya dijo que las dos grafías son el mismo colegio: la consulta no
        // puede volver a separarlas.
        $this->sync()->aplicar($votante, $this->datos(['puesto_votacion' => 'COL. SAN SIMON']));

        $this->assertSame($canonico->id, $votante->fresh()->voting_place_id);
        $this->assertNotSame($variante->id, $votante->fresh()->voting_place_id);
    }

    public function test_la_fusion_de_una_campana_no_resuelve_la_de_otra(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $variante = $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);

        // Solo esta campaña fusionó. La otra sigue con las dos grafías separadas.
        $this->fusionar($this->tenant, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $ajeno = Tenant::factory()->create();
        $votanteAjeno = $this->votante($ajeno);

        app()->instance('current_tenant_id', $ajeno->id);

        $this->sync()->aplicar($votanteAjeno, $this->datos(['puesto_votacion' => 'COL. SAN SIMON']));

        $suyo = Voter::withoutGlobalScope(TenantScope::class)->find($votanteAjeno->id);

        $this->assertSame($variante->id, $suyo->voting_place_id);
        $this->assertNotSame($canonico->id, $suyo->voting_place_id);
    }

    public function test_reprocesar_los_mismos_datos_no_duplica_ni_cambia_el_id(): void
    {
        $votante = $this->votante();

        $this->sync()->aplicar($votante, $this->datos());
        $primerId = $votante->fresh()->voting_place_id;

        $this->sync()->aplicar($votante->fresh(), $this->datos());

        $this->assertSame($primerId, $votante->fresh()->voting_place_id);
        $this->assertSame(1, VotingPlace::count());
    }

    public function test_sin_departamento_casa_con_el_catalogo_pero_no_da_de_alta(): void
    {
        $votante = $this->votante();

        // El departamento es parte de la clave natural del catálogo y no se
        // rellena con un placeholder (coherente con `resolverActa`).
        $this->sync()->aplicar($votante, $this->datos(['departamento_votacion' => null]));

        $this->assertSame(0, VotingPlace::count());
        $this->assertNull($votante->fresh()->voting_place_id);
        // Pero la ubicación por nombre sí se guarda: el respaldo de la 0075 la
        // vuelve a intentar y la conciliación de la 0062 la ve.
        $this->assertSame(self::CANONICO, $votante->fresh()->puesto_votacion);

        $canonico = $this->puestoDelCatalogo();

        $this->sync()->aplicar($votante->fresh(), $this->datos(['departamento_votacion' => null]));

        $this->assertSame($canonico->id, $votante->fresh()->voting_place_id);
    }

    public function test_una_ubicacion_incompleta_no_toca_al_votante(): void
    {
        $votante = $this->votante();

        $this->sync()->aplicar($votante, $this->datos(['puesto_votacion' => null]));

        // Dejarle nulos encima sería peor que no saber: el backfill lo vuelve a
        // intentar porque sigue sin puesto.
        $this->assertNull($votante->fresh()->municipio_votacion);
        $this->assertNull($votante->fresh()->puesto_votacion);
        $this->assertSame(0, VotingPlace::count());
    }

    public function test_una_mesa_no_numerica_ya_no_se_pierde(): void
    {
        $votante = $this->votante();

        // El viejo webhook la validaba como entero (`nullable|integer`) y
        // rechazaba la petición entera; la columna siempre fue `string(20)`.
        $this->sync()->aplicar($votante, $this->datos(['mesa_votacion' => '12A']));

        $this->assertSame('12A', $votante->fresh()->mesa_votacion);
    }
}

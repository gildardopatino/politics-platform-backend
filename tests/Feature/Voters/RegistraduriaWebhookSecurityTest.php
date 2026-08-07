<?php

namespace Tests\Feature\Voters;

use App\Models\Tenant;
use App\Models\Voter;
use Tests\TestCase;

/**
 * Cierre de la exfiltración y el tampering sin autenticar (Spec 0030).
 *
 * Los dos webhooks de Registraduría eran públicos, sin token ni firma ni
 * `throttle`, y consultaban con `withoutGlobalScope(TenantScope::class)`:
 * `pendientes` repartía hasta 100 cédulas de cualquier campaña y `actualizar`
 * escribía en el votante de cualquier tenant y devolvía el modelo completo con
 * su PII. Encadenados vaciaban la base de votantes de toda la plataforma
 * (caracterizado en la Spec 0011).
 *
 * Ahora cada tenant tiene **su propio secreto**, que viaja en
 * `X-Registraduria-Secret`. El secreto autentica **e identifica**: no hay campo
 * de tenant en el payload que un atacante pueda elegir, y una fuga compromete
 * una campaña, no la plataforma.
 */
class RegistraduriaWebhookSecurityTest extends TestCase
{
    private const CABECERA = 'X-Registraduria-Secret';

    private Tenant $tenant;

    private string $secreto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->secreto = $this->tenant->generarSecretoRegistraduria();
    }

    // ==================================================================
    // Sin secreto no se entra
    // ==================================================================

    public function test_pendientes_sin_secreto_responde_401(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        $this->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401)
            ->assertJsonPath('error', 'WEBHOOK_SECRET_MISSING');
    }

    public function test_actualizar_sin_secreto_responde_401_y_no_escribe(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create(['puesto_votacion' => null]);

        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $votante->id,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'PUESTO FALSO',
        ])->assertStatus(401);

        $this->assertNull($votante->fresh()->puesto_votacion);
    }

    public function test_un_secreto_que_no_es_de_nadie_responde_401(): void
    {
        $this->withHeader(self::CABECERA, 'secreto-inventado')
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401)
            ->assertJsonPath('error', 'WEBHOOK_SECRET_INVALID');
    }

    public function test_un_secreto_vacio_responde_401(): void
    {
        $this->withHeader(self::CABECERA, '')
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401);
    }

    public function test_el_secreto_de_un_tenant_borrado_deja_de_valer(): void
    {
        $this->tenant->delete();

        $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401);
    }

    public function test_un_tenant_con_la_vigencia_vencida_no_sincroniza(): void
    {
        $vencido = Tenant::factory()->expired()->create();
        $secreto = $vencido->generarSecretoRegistraduria();

        $this->withHeader(self::CABECERA, $secreto)
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(403)
            ->assertJsonPath('error', 'TENANT_EXPIRED');
    }

    public function test_con_el_secreto_correcto_si_se_entra(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    // ==================================================================
    // Límite de peticiones
    // ==================================================================

    public function test_las_dos_rutas_tienen_limite_de_peticiones(): void
    {
        // Sin límite, el secreto se puede intentar a fuerza bruta y `pendientes`
        // se puede sondear en bucle.
        for ($i = 0; $i < 60; $i++) {
            $this->conSecreto()
                ->getJson('/api/v1/webhook/political/registraduria/pendientes')
                ->assertStatus(200);
        }

        $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(429);
    }

    // ==================================================================
    // Cada secreto ve solo lo suyo
    // ==================================================================

    public function test_pendientes_solo_devuelve_los_votantes_del_tenant_del_secreto(): void
    {
        $otro = Tenant::factory()->create();

        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001', 'departamento_votacion' => null,
        ]);
        Voter::factory()->forTenant($otro)->create([
            'cedula' => '72000009', 'departamento_votacion' => null,
        ]);

        $cedulas = $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->json('*.cedula');

        $this->assertSame(['71000001'], $cedulas);
    }

    public function test_pendientes_no_filtra_campos_que_nadie_pidio(): void
    {
        Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        $fila = $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->json('0');

        // Antes se colaban `full_name` y `location_type` por los `$appends` del
        // modelo, pese al `select('id','cedula')`.
        $this->assertSame(['id', 'cedula'], array_keys($fila));
    }

    public function test_actualizar_no_toca_al_votante_de_otra_campania(): void
    {
        // La prueba de no-fuga: con el secreto del tenant A, el votante de B es
        // como si no existiera.
        $otro = Tenant::factory()->create();
        $ajeno = Voter::factory()->forTenant($otro)->create([
            'puesto_votacion' => null,
            'departamento_votacion' => null,
        ]);

        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
                'id' => $ajeno->id,
                'departamento_votacion' => 'INVENTADO',
                'municipio_votacion' => 'INVENTADO',
                'puesto_votacion' => 'PUESTO FALSO',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['id']]);

        $ajeno->refresh();
        $this->assertNull($ajeno->puesto_votacion);
        $this->assertNull($ajeno->departamento_votacion);
    }

    public function test_un_id_inexistente_y_uno_de_otra_campania_dan_la_misma_respuesta(): void
    {
        // Si «no existe» y «existe pero no es tuyo» se distinguieran, el webhook
        // serviría para averiguar qué ids tiene la competencia.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $payload = [
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ];

        $deOtro = $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => $ajeno->id] + $payload);
        $inexistente = $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => 999999] + $payload);

        $this->assertSame($deOtro->status(), $inexistente->status());
        $this->assertSame($deOtro->json(), $inexistente->json());
    }

    public function test_el_secreto_de_cada_tenant_abre_solo_su_puerta(): void
    {
        $otro = Tenant::factory()->create();
        $secretoDelOtro = $otro->generarSecretoRegistraduria();

        Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '71000001', 'departamento_votacion' => null,
        ]);
        Voter::factory()->forTenant($otro)->create([
            'cedula' => '72000009', 'departamento_votacion' => null,
        ]);

        $this->assertSame(
            ['71000001'],
            $this->conSecreto()->getJson('/api/v1/webhook/political/registraduria/pendientes')->json('*.cedula')
        );

        $this->assertSame(
            ['72000009'],
            $this->withHeader(self::CABECERA, $secretoDelOtro)
                ->getJson('/api/v1/webhook/political/registraduria/pendientes')
                ->json('*.cedula')
        );
    }

    // ==================================================================
    // La respuesta de actualizar no lleva PII
    // ==================================================================

    public function test_actualizar_responde_un_acuse_minimo(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'email' => 'ana@ejemplo.test',
            'telefono' => '3001112233',
            'direccion' => 'Calle 50 #45-30',
        ]);

        $respuesta = $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
                'id' => $votante->id,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => 'IE EL CENTRO',
                'direccion_votacion' => 'CALLE 10 # 5-20',
                'mesa_votacion' => 12,
            ])->assertStatus(200);

        $respuesta->assertJsonPath('success', true);
        $this->assertSame(['id', 'updated'], array_keys($respuesta->json('data')));
        $respuesta->assertJsonPath('data.id', $votante->id);
        $respuesta->assertJsonPath('data.updated', true);

        // Ni PII ni `tenant_id` en ninguna parte del cuerpo.
        $cuerpo = $respuesta->getContent();
        foreach (['Ana María', 'Restrepo', 'ana@ejemplo.test', '3001112233', 'Calle 50', 'tenant_id'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo);
        }
    }

    public function test_actualizar_sigue_escribiendo_el_puesto_de_votacion(): void
    {
        // El arreglo no puede romper la sincronización legítima.
        $votante = Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
                'id' => $votante->id,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => 'IE EL CENTRO',
                'direccion_votacion' => 'CALLE 10 # 5-20',
                'mesa_votacion' => 12,
            ])->assertStatus(200);

        $votante->refresh();

        $this->assertSame('TOLIMA', $votante->departamento_votacion);
        $this->assertSame('IE EL CENTRO', $votante->puesto_votacion);
        $this->assertSame('12', (string) $votante->mesa_votacion);
        $this->assertNotNull($votante->voting_place_id);
    }

    // ==================================================================
    // El secreto en sí
    // ==================================================================

    public function test_el_secreto_no_se_guarda_en_claro(): void
    {
        $this->tenant->refresh();

        $this->assertNotSame($this->secreto, $this->tenant->registraduria_secret_hash);
        $this->assertSame(hash('sha256', $this->secreto), $this->tenant->registraduria_secret_hash);
    }

    public function test_rotar_el_secreto_invalida_el_anterior(): void
    {
        $nuevo = $this->tenant->generarSecretoRegistraduria();

        $this->assertNotSame($this->secreto, $nuevo);

        $this->withHeader(self::CABECERA, $this->secreto)
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401);

        $this->withHeader(self::CABECERA, $nuevo)
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200);
    }

    public function test_un_tenant_sin_secreto_no_habilita_la_ruta(): void
    {
        // La columna es nullable: los tenants que nunca lo generaron no pueden
        // sincronizar, y un secreto nulo no debe casar con una cabecera vacía.
        $sinSecreto = Tenant::factory()->create();

        $this->assertNull($sinSecreto->registraduria_secret_hash);

        $this->withHeader(self::CABECERA, '')
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(401);
    }

    // ==================================================================

    private function conSecreto(): static
    {
        return $this->withHeader(self::CABECERA, $this->secreto);
    }
}

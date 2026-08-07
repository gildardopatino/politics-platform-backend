<?php

namespace Tests\Feature\Voters;

use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN de los webhooks de Registraduría (Spec 0011).
 *
 * Dos rutas **públicas**, pensadas para que n8n complete la información
 * electoral de los votantes:
 *
 * - `GET  /webhook/political/registraduria/pendientes` → a quién le falta el dato
 * - `POST /webhook/political/registraduria/actualizar` → escribe el dato
 *
 * Ambas viven fuera del grupo `jwt.auth`, **sin token ni firma**, y consultan
 * con `withoutGlobalScope(TenantScope::class)` de forma deliberada porque no hay
 * contexto de tenant. Eso es exactamente el patrón que la Spec 0026 cerró en
 * `verify-document`, y aquí sigue abierto: estas pruebas lo fijan con evidencia
 * en vez de corregirlo (ver `known-issues.md`).
 */
class RegistraduriaWebhookCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nada de este flujo debe salir a la red desde la suite.
        Http::preventStrayRequests();
    }

    // ==================================================================
    // Qué hacen
    // ==================================================================

    public function test_pendientes_lista_a_quien_le_falta_el_departamento(): void
    {
        $tenant = Tenant::factory()->create();
        $sinDato = Voter::factory()->forTenant($tenant)->create(['departamento_votacion' => null]);
        Voter::factory()->forTenant($tenant)->create(['departamento_votacion' => 'TOLIMA']);

        $respuesta = $this->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200);

        // Array crudo, sin envoltorio ni paginación.
        $respuesta->assertJsonCount(1);
        $respuesta->assertJsonPath('0.id', $sinDato->id);
        $respuesta->assertJsonPath('0.cedula', $sinDato->cedula);

        // El controlador hace `select('id', 'cedula')`, pero el modelo tiene
        // `$appends = ['full_name', 'location_type']` y esos viajan igual. Como
        // `nombres`/`apellidos` no se seleccionaron, `full_name` sale vacío: dos
        // campos de ruido que el consumidor no pidió.
        $this->assertSame(
            ['id', 'cedula', 'full_name', 'location_type'],
            array_keys($respuesta->json('0'))
        );
        $this->assertSame('', $respuesta->json('0.full_name'));
    }

    public function test_pendientes_corta_en_cien(): void
    {
        $tenant = Tenant::factory()->create();
        Voter::factory()->forTenant($tenant)->count(101)->create(['departamento_votacion' => null]);

        // Sin paginación ni cursor: el consumidor no tiene forma de pedir el
        // resto salvo volver a llamar tras actualizar los primeros 100.
        $this->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->assertJsonCount(100);
    }

    public function test_actualizar_escribe_el_puesto_y_crea_el_registro_del_puesto(): void
    {
        $tenant = Tenant::factory()->create();
        $votante = Voter::factory()->forTenant($tenant)->create(['departamento_votacion' => null]);

        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $votante->id,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
            'direccion_votacion' => 'CALLE 10 # 5-20',
            'mesa_votacion' => 12,
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Información de registraduría actualizada correctamente.');

        $votante->refresh();

        $this->assertSame('TOLIMA', $votante->departamento_votacion);
        $this->assertSame('IE EL CENTRO', $votante->puesto_votacion);
        $this->assertSame('12', (string) $votante->mesa_votacion);

        // `voting_places` es un catálogo global (no usa `HasTenant`) que solo se
        // alimenta desde aquí: no hay CRUD para él en ninguna ruta.
        $puesto = VotingPlace::sole();
        $this->assertSame('IE EL CENTRO', $puesto->puesto_votacion);
        $this->assertSame($puesto->id, $votante->voting_place_id);
    }

    public function test_actualizar_reutiliza_el_puesto_ya_registrado(): void
    {
        $tenant = Tenant::factory()->create();
        $primero = Voter::factory()->forTenant($tenant)->create();
        $segundo = Voter::factory()->forTenant($tenant)->create();

        $payload = [
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ];

        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => $primero->id] + $payload);
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => $segundo->id] + $payload);

        $this->assertSame(1, VotingPlace::count());
        $this->assertSame($primero->fresh()->voting_place_id, $segundo->fresh()->voting_place_id);
    }

    public function test_actualizar_valida_el_payload(): void
    {
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion']]);
    }

    public function test_actualizar_con_un_id_inexistente_da_422_no_404(): void
    {
        // La regla `exists:voters,id` corta antes que el `find()`, así que la
        // rama que devuelve 404 «Votante no encontrado» es inalcanzable.
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => 999999,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['id']]);
    }

    public function test_mesa_votacion_se_valida_como_entero_aunque_la_columna_sea_texto(): void
    {
        $tenant = Tenant::factory()->create();
        $votante = Voter::factory()->forTenant($tenant)->create();

        // `voters.mesa_votacion` es string(20) y el formulario interno la acepta
        // como texto; este webhook exige entero, así que "12A" —una mesa válida
        // en algunos puestos— se rechaza.
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $votante->id,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
            'mesa_votacion' => '12A',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['mesa_votacion']]);
    }

    // ==================================================================
    // Seguridad — el patrón de la Spec 0026, todavía abierto
    // ==================================================================

    public function test_hueco_pendientes_es_publico_y_reparte_cedulas_de_todos_los_tenants(): void
    {
        // Sin sesión, sin token y sin firma: cualquiera que sepa la URL obtiene
        // hasta 100 cédulas de CUALQUIER campaña. La 0026 cerró un agujero de la
        // misma familia, pero aquel exigía conocer ya la cédula; este las
        // reparte.
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Voter::factory()->forTenant($tenantA)->create(['cedula' => '71000001', 'departamento_votacion' => null]);
        Voter::factory()->forTenant($tenantB)->create(['cedula' => '72000009', 'departamento_votacion' => null]);

        $this->flushHeaders();

        $cedulas = $this->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->json('*.cedula');

        $this->assertContains('71000001', $cedulas);
        $this->assertContains('72000009', $cedulas, 'Reparte cédulas de otro tenant.');
    }

    public function test_hueco_actualizar_es_publico_y_escribe_en_el_votante_de_cualquier_tenant(): void
    {
        // Escritura sin autenticar sobre datos de otra campaña.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create([
            'departamento_votacion' => null,
        ]);

        $this->flushHeaders();

        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $ajeno->id,
            'departamento_votacion' => 'INVENTADO',
            'municipio_votacion' => 'INVENTADO',
            'puesto_votacion' => 'PUESTO FALSO',
        ])->assertStatus(200);

        $this->assertSame('PUESTO FALSO', $ajeno->fresh()->puesto_votacion);
    }

    public function test_hueco_actualizar_devuelve_el_votante_entero_y_sirve_de_oraculo_de_pii(): void
    {
        // La respuesta incluye `data => $voter->fresh()`, es decir el modelo
        // COMPLETO: nombres, apellidos, correo, teléfono, dirección y
        // `tenant_id`. Encadenado con `pendientes` —que entrega los ids— permite
        // vaciar la base de votantes de todas las campañas sin autenticarse.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create([
            'nombres' => 'Ana María',
            'apellidos' => 'Restrepo Gómez',
            'email' => 'ana@otra-campania.test',
            'telefono' => '3001112233',
            'direccion' => 'Calle 50 #45-30',
        ]);

        $this->flushHeaders();

        $datos = $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $ajeno->id,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ])->assertStatus(200)->json('data');

        $this->assertSame('Ana María', $datos['nombres']);
        $this->assertSame('ana@otra-campania.test', $datos['email']);
        $this->assertSame('3001112233', $datos['telefono']);
        $this->assertSame('Calle 50 #45-30', $datos['direccion']);
        $this->assertSame($ajeno->tenant_id, $datos['tenant_id']);
    }

    public function test_hueco_los_webhooks_no_tienen_limite_de_peticiones(): void
    {
        // Ni `throttle` ni firma: se puede barrer el espacio de ids a ritmo
        // libre. `verify-document` sí quedó con `throttle:20,1` tras la 0026.
        $tenant = Tenant::factory()->create();
        Voter::factory()->forTenant($tenant)->create(['departamento_votacion' => null]);

        $this->flushHeaders();

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/webhook/political/registraduria/pendientes')->assertStatus(200);
        }
    }

    public function test_el_webhook_de_actualizar_no_deja_el_tenant_enlazado(): void
    {
        // Confirmación de que la ruta no establece contexto: `TenantScope` no
        // filtra nada durante toda la petición.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create();

        $this->flushHeaders();

        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', [
            'id' => $ajeno->id,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ])->assertStatus(200);

        $this->assertFalse(app()->bound('current_tenant_id'));
        $this->assertSame(1, Voter::withoutGlobalScope(TenantScope::class)->count());
    }
}

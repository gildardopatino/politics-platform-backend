<?php

namespace Tests\Feature\Voters;

use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contrato de los webhooks de Registraduría (Spec 0011, cerrado por la 0030).
 *
 * Dos rutas para que n8n complete la información electoral de los votantes:
 *
 * - `GET  /webhook/political/registraduria/pendientes` → a quién le falta el dato
 * - `POST /webhook/political/registraduria/actualizar` → escribe el dato
 *
 * Nacieron **públicas**, sin token ni firma, consultando con
 * `withoutGlobalScope(TenantScope::class)`: repartían cédulas de todas las
 * campañas y dejaban escribir en sus votantes. Esta clase fijaba ese hueco; la
 * Spec 0030 lo cerró con un secreto por tenant en `X-Registraduria-Secret`, y
 * las pruebas cambiaron de signo — que es para lo que estaban escritas.
 *
 * El contrato de seguridad completo vive en
 * `RegistraduriaWebhookSecurityTest`; aquí queda lo que los webhooks **hacen**.
 */
class RegistraduriaWebhookCharacterizationTest extends TestCase
{
    private Tenant $tenant;

    private string $secreto;

    protected function setUp(): void
    {
        parent::setUp();

        // Nada de este flujo debe salir a la red desde la suite.
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->secreto = $this->tenant->generarSecretoRegistraduria();
    }

    // ==================================================================
    // Qué hacen
    // ==================================================================

    public function test_pendientes_lista_a_quien_le_falta_el_departamento(): void
    {
        $sinDato = Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);
        Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => 'TOLIMA']);

        $respuesta = $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200);

        // Array crudo, sin envoltorio ni paginación, y solo `id` + `cedula`.
        $respuesta->assertJsonCount(1);
        $respuesta->assertJsonPath('0.id', $sinDato->id);
        $respuesta->assertJsonPath('0.cedula', $sinDato->cedula);
        $this->assertSame(['id', 'cedula'], array_keys($respuesta->json('0')));
    }

    public function test_pendientes_corta_en_cien(): void
    {
        Voter::factory()->forTenant($this->tenant)->count(101)->create(['departamento_votacion' => null]);

        // Sin paginación ni cursor: el consumidor no tiene forma de pedir el
        // resto salvo volver a llamar tras actualizar los primeros 100.
        $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(200)
            ->assertJsonCount(100);
    }

    public function test_actualizar_escribe_el_puesto_y_crea_el_registro_del_puesto(): void
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
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
        $primero = Voter::factory()->forTenant($this->tenant)->create();
        $segundo = Voter::factory()->forTenant($this->tenant)->create();

        $payload = [
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'IE EL CENTRO',
        ];

        $this->conSecreto()->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => $primero->id] + $payload);
        $this->conSecreto()->postJson('/api/v1/webhook/political/registraduria/actualizar', ['id' => $segundo->id] + $payload);

        $this->assertSame(1, VotingPlace::count());
        $this->assertSame($primero->fresh()->voting_place_id, $segundo->fresh()->voting_place_id);
    }

    public function test_actualizar_valida_el_payload(): void
    {
        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion']]);
    }

    public function test_actualizar_con_un_id_inexistente_da_422_no_404(): void
    {
        // La regla `exists` —ahora acotada al tenant del secreto— corta antes que
        // el `find()`, así que la rama que devuelve 404 sigue siendo inalcanzable.
        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
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
        $votante = Voter::factory()->forTenant($this->tenant)->create();

        // `voters.mesa_votacion` es string(20) y el formulario interno la acepta
        // como texto; este webhook exige entero, así que "12A" —una mesa válida
        // en algunos puestos— se rechaza. Sigue abierto en `known-issues.md`.
        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
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
    // Lo que era un hueco y ahora es la regla (Spec 0030)
    // ==================================================================

    public function test_pendientes_ya_no_reparte_cedulas_de_todos_los_tenants(): void
    {
        // Antes: sin sesión, sin token y sin firma, cualquiera que supiera la URL
        // obtenía hasta 100 cédulas de CUALQUIER campaña.
        $otro = Tenant::factory()->create();

        Voter::factory()->forTenant($this->tenant)->create(['cedula' => '71000001', 'departamento_votacion' => null]);
        Voter::factory()->forTenant($otro)->create(['cedula' => '72000009', 'departamento_votacion' => null]);

        $this->flushHeaders();
        $this->getJson('/api/v1/webhook/political/registraduria/pendientes')->assertStatus(401);

        // Y con secreto, solo lo propio.
        $this->assertSame(
            ['71000001'],
            $this->conSecreto()->getJson('/api/v1/webhook/political/registraduria/pendientes')->json('*.cedula')
        );
    }

    public function test_actualizar_ya_no_escribe_en_el_votante_de_cualquier_tenant(): void
    {
        // Antes: escritura sin autenticar sobre datos de otra campaña.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create([
            'departamento_votacion' => null,
        ]);

        $payload = [
            'id' => $ajeno->id,
            'departamento_votacion' => 'INVENTADO',
            'municipio_votacion' => 'INVENTADO',
            'puesto_votacion' => 'PUESTO FALSO',
        ];

        $this->flushHeaders();
        $this->postJson('/api/v1/webhook/political/registraduria/actualizar', $payload)->assertStatus(401);

        // Ni siquiera con un secreto válido de otra campaña.
        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', $payload)
            ->assertStatus(422);

        $this->assertNull($ajeno->fresh()->puesto_votacion);
    }

    public function test_actualizar_ya_no_devuelve_el_votante_entero(): void
    {
        // Antes respondía `data => $voter->fresh()`: el modelo COMPLETO
        // —nombres, correo, teléfono, dirección y `tenant_id`—. Encadenado con
        // `pendientes` permitía vaciar la base de votantes de todas las campañas
        // sin autenticarse.
        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'nombres' => 'Ana María',
            'email' => 'ana@ejemplo.test',
            'telefono' => '3001112233',
        ]);

        $respuesta = $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
                'id' => $votante->id,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => 'IE EL CENTRO',
            ])->assertStatus(200);

        $this->assertSame(['id', 'updated'], array_keys($respuesta->json('data')));

        foreach (['Ana María', 'ana@ejemplo.test', '3001112233', 'tenant_id'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $respuesta->getContent());
        }
    }

    public function test_los_webhooks_tienen_limite_de_peticiones(): void
    {
        // Antes no había ni `throttle` ni firma: se podía barrer el espacio de
        // ids a ritmo libre, y ahora también tantear el secreto.
        Voter::factory()->forTenant($this->tenant)->create(['departamento_votacion' => null]);

        for ($i = 0; $i < 60; $i++) {
            $this->conSecreto()
                ->getJson('/api/v1/webhook/political/registraduria/pendientes')
                ->assertStatus(200);
        }

        $this->conSecreto()
            ->getJson('/api/v1/webhook/political/registraduria/pendientes')
            ->assertStatus(429);
    }

    public function test_el_webhook_de_actualizar_opera_con_el_tenant_del_secreto_enlazado(): void
    {
        // Antes la ruta no establecía contexto y `TenantScope` no filtraba nada
        // durante toda la petición. Ahora el secreto lo fija.
        $ajeno = Voter::factory()->forTenant(Tenant::factory()->create())->create();
        $propio = Voter::factory()->forTenant($this->tenant)->create();

        $this->conSecreto()
            ->postJson('/api/v1/webhook/political/registraduria/actualizar', [
                'id' => $propio->id,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => 'IE EL CENTRO',
            ])->assertStatus(200);

        $this->assertSame(2, Voter::withoutGlobalScope(TenantScope::class)->count());
        $this->assertNull($ajeno->fresh()->puesto_votacion);
    }

    // ==================================================================

    private function conSecreto(): static
    {
        return $this->withHeader('X-Registraduria-Secret', $this->secreto);
    }
}

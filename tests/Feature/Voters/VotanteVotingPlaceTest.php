<?php

namespace Tests\Feature\Voters;

use App\Models\E14PuestoAlias;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La captura del votante resuelve al puesto canónico (Spec 0075).
 *
 * La 0062 dejó el cruce colgando de un cabo suelto: el lado E-14 resolvía el
 * puesto por nombre **normalizado** (más los alias del tenant) y la escritura del
 * votante casaba por igualdad exacta de cadenas con su propio `firstOrCreate`. Dos
 * grafías del mismo colegio creaban dos renglones del catálogo, el acta apuntaba a
 * uno y el votante al otro, y el cruce por `voting_place_id` **fallaba en
 * silencio** — peor que un nulo, porque el resolver de respaldo solo rescata los
 * nulos y un id equivocado no-nulo nunca cae en él.
 *
 * Aquí se fija que las tres escrituras del votante pasan por el mismo
 * `PuestoResolver`, con la asimetría que la spec decidió:
 *
 * - **Registraduría** (el censo oficial) → find-or-create normalizado;
 * - **alta/edición manual** (texto tecleado) → solo buscar, nunca crear.
 *
 * Desde la Spec 0091 el lado autoritativo ya no entra por el webhook de n8n sino
 * por la cola (`RegistraduriaSyncService`); sus invariantes se prueban en
 * `tests/Unit/Services/Registraduria/RegistraduriaSyncServiceTest.php` y aquí
 * queda lo que cruza capas: el resolver puro, la captura manual y el cruce.
 */
class VotanteVotingPlaceTest extends TestCase
{
    private const CANONICO = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
    }

    /** Un renglón del catálogo global, como el que crea un acta. */
    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ], $cambios));
    }

    private function votante(?Tenant $tenant = null, array $atributos = []): Voter
    {
        return Voter::factory()->forTenant($tenant ?? $this->tenant)->create($atributos);
    }

    /**
     * Lo que hoy escribe el lado autoritativo: la cola de la Spec 0091 aplicando
     * lo que devolvió el servicio de Registraduría. Antes era el webhook de n8n;
     * el guardado es el mismo (`RegistraduriaSyncService`).
     *
     * @param  array<string, mixed>  $cambios  claves del contrato del servicio
     */
    private function registraduria(Voter $votante, array $cambios = []): void
    {
        app()->instance('current_tenant_id', $votante->tenant_id);

        app(\App\Services\Registraduria\RegistraduriaSyncService::class)->aplicar(
            $votante,
            \App\Services\Registraduria\RegistraduriaSyncService::mapear(array_replace([
                'DEPARTAMENTO' => 'TOLIMA',
                'MUNICIPIO' => 'IBAGUE',
                'PUESTO' => self::CANONICO,
                'MESA' => '5',
            ], $cambios))
        );
    }

    /** Fusión decidida por el tenant: «este nombre va a este puesto». */
    private function fusionar(Tenant $tenant, string $municipio, string $puesto, VotingPlace $destino): void
    {
        E14PuestoAlias::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'clave' => \App\Services\E14\PuestoResolver::clave($municipio, $puesto),
            'voting_place_id' => $destino->id,
            'municipio' => $municipio,
            'puesto' => $puesto,
        ]);
    }

    // ======================================== el lado autoritativo (Registraduría)

    public function test_el_resolver_autoritativo_no_crea_puesto_sin_departamento(): void
    {
        $resolver = app(\App\Services\E14\PuestoResolver::class);

        // El departamento es parte de la clave natural del catálogo y no se
        // rellena con un placeholder: coherente con `resolverActa`.
        $this->assertNull($resolver->resolverRegistraduria(null, 'IBAGUE', 'PUESTO NUEVO'));
        $this->assertSame(0, VotingPlace::count());

        // Sin municipio o sin puesto tampoco hay llave que resolver.
        $this->assertNull($resolver->resolverRegistraduria('TOLIMA', null, 'PUESTO NUEVO'));
        $this->assertNull($resolver->resolverRegistraduria('TOLIMA', 'IBAGUE', ''));
        $this->assertSame(0, VotingPlace::count());

        // Pero si ya está en el catálogo, resuelve sin necesitar el departamento:
        // lo común entre los dos lados es municipio + puesto.
        $canonico = $this->puestoDelCatalogo();
        $resolver->refrescar();

        $this->assertSame($canonico->id, $resolver->resolverRegistraduria(null, 'ibague', 'Colegio San Simón'));
        $this->assertSame(1, VotingPlace::count());
    }

    // ====================================================== alta y edición

    /** Se autentica como operador del tenant de la clase. */
    private function operador(): void
    {
        // `store` fuerza `tipo_votante_id = 1` y la columna es NOT NULL con FK;
        // en pruebas no corre `TipoVotanteSeeder`, así que se garantiza aquí (lo
        // mismo que hace `VoterFactory`).
        \App\Models\TipoVotante::firstOrCreate(['descripcion' => 'Elector']);

        [$user, $token] = $this->createTenantWithUser(['view_voters'], $this->tenant);

        $this->actingAsTenantUser($user, $token);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function formulario(array $cambios = []): array
    {
        return array_replace([
            'cedula' => '1110002222',
            'nombres' => 'ANA',
            'apellidos' => 'GOMEZ',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'mesa_votacion' => '5',
        ], $cambios);
    }

    public function test_el_alta_manual_nunca_crea_un_puesto_en_el_catalogo(): void
    {
        $this->operador();

        // Un nombre tecleado a mano no da de alta un renglón del catálogo global:
        // el votante queda sin puesto y lo recoge la conciliación de la 0062.
        $respuesta = $this->postJson('/api/v1/voters', $this->formulario())->assertStatus(201);

        $this->assertNull($respuesta->json('data.voting_place_id'));
        $this->assertSame(0, VotingPlace::count());
    }

    public function test_el_alta_manual_casa_con_el_puesto_existente_aunque_cambie_la_grafia(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $this->operador();

        $this->postJson('/api/v1/voters', $this->formulario([
            'municipio_votacion' => 'Ibagué',
            'puesto_votacion' => 'colegio san simón',
        ]))->assertStatus(201)
            ->assertJsonPath('data.voting_place_id', $canonico->id);

        $this->assertSame(1, VotingPlace::count());
    }

    public function test_el_alta_manual_respeta_la_fusion_de_la_campana(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $this->puestoDelCatalogo(['puesto_votacion' => 'COL. SAN SIMON']);
        $this->fusionar($this->tenant, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $this->operador();

        $this->postJson('/api/v1/voters', $this->formulario(['puesto_votacion' => 'COL. SAN SIMON']))
            ->assertStatus(201)
            ->assertJsonPath('data.voting_place_id', $canonico->id);
    }

    public function test_editar_a_otra_grafia_del_mismo_sitio_mantiene_el_canonico(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante(atributos: [
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'voting_place_id' => $canonico->id,
        ]);

        $this->operador();

        $this->putJson("/api/v1/voters/{$votante->id}", $this->formulario([
            'cedula' => $votante->cedula,
            'puesto_votacion' => 'Colegio San Simón',
        ]))->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $canonico->id);
    }

    public function test_vaciar_el_puesto_o_el_municipio_deja_el_votante_sin_puesto(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante(atributos: [
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'voting_place_id' => $canonico->id,
        ]);

        $this->operador();

        // Un puesto que ya no corresponde a la ubicación no se conserva.
        $this->putJson("/api/v1/voters/{$votante->id}", $this->formulario([
            'cedula' => $votante->cedula,
            'puesto_votacion' => null,
        ]))->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', null);

        $this->assertNull($votante->fresh()->voting_place_id);
    }

    public function test_una_edicion_que_no_toca_la_ubicacion_no_cambia_el_puesto(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante(atributos: [
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
            'voting_place_id' => $canonico->id,
        ]);

        $this->operador();

        // Cambiar el teléfono no puede mover a nadie de puesto.
        $this->patchJson("/api/v1/voters/{$votante->id}", [
            'cedula' => $votante->cedula,
            'nombres' => $votante->nombres,
            'apellidos' => $votante->apellidos,
            'telefono' => '3001234567',
        ])->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $canonico->id);
    }

    public function test_editar_solo_el_puesto_resuelve_con_el_municipio_que_ya_tiene(): void
    {
        $canonico = $this->puestoDelCatalogo();
        $votante = $this->votante(atributos: [
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'OTRO PUESTO',
            'voting_place_id' => null,
        ]);

        $this->operador();

        // El municipio no viaja en la petición: se resuelve con la ubicación con
        // la que **queda** el votante, no solo con lo que trae el request.
        $this->patchJson("/api/v1/voters/{$votante->id}", [
            'cedula' => $votante->cedula,
            'nombres' => $votante->nombres,
            'apellidos' => $votante->apellidos,
            'puesto_votacion' => self::CANONICO,
        ])->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $canonico->id);
    }

    public function test_el_cliente_no_puede_elegir_el_puesto_a_mano(): void
    {
        $this->puestoDelCatalogo();
        $ajeno = $this->puestoDelCatalogo(['puesto_votacion' => 'PUESTO QUE NO ES SUYO']);

        $this->operador();

        // `voting_place_id` se deriva siempre en el servidor de municipio+puesto.
        $this->postJson('/api/v1/voters', $this->formulario([
            'puesto_votacion' => 'PUESTO INEXISTENTE',
            'voting_place_id' => $ajeno->id,
        ]))->assertStatus(201)
            ->assertJsonPath('data.voting_place_id', null);
    }

    // ================================================= coherencia del cruce

    public function test_el_votante_de_registraduria_casa_con_el_acta_en_el_cruce(): void
    {
        $votante = $this->votante();

        // El acta llega con una grafía y Registraduría con otra: el cruce tiene que
        // verlos en la **misma** fila, sin depender del resolver de respaldo.
        [$user, $token] = $this->createTenantWithUser(
            [\App\Support\Permissions::VIEW_E14, \App\Support\Permissions::MANAGE_E14],
            $this->tenant
        );
        $this->actingAsTenantUser($user, $token);

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => 'alcaldia',
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '005',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::CANONICO,
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ])->assertStatus(201);

        \App\Models\ElectoralEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->update(['candidato_propio_numero' => 2]);

        $this->registraduria($votante, [
            'MUNICIPIO' => 'Ibagué',
            'PUESTO' => 'colegio san simón ',
            'MESA' => '5',
        ]);

        $this->actingAsTenantUser($user, $token);

        $this->getJson('/api/v1/e14/cruce?nivel=mesa')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mesa', 5)
            ->assertJsonPath('data.0.base', 1)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('meta.cobertura.base_sin_conciliar', 0);
    }
}

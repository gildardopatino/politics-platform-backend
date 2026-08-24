<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El consolidado de corporación, en dos niveles (Spec 0067 · Parte B · RF-B4).
 *
 * El de un uninominal es una columna: cuántos votos sacó cada candidato. El de
 * una corporación son dos, y los dos hacen falta — el total por **lista** es la
 * cifra con la que se reparten curules, y el de cada **preferente** dice quién
 * se las lleva dentro de la lista.
 *
 * Solo entran las actas `procesada`, igual que en el uninominal: sumar una que
 * no cuadra sería propagar un error conocido a la cifra que después alguien va a
 * mirar para tomar decisiones.
 */
class E14ConsolidadoCorporacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        // Una campaña de concejo, porque desde la 0093 el consolidado es el de
        // **su** elección: el `?tipo=` de la URL ya no elige nada.
        $tenant ??= Tenant::factory()->corporacion()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * Un acta de concejo que cuadra: 10 + 5 + 0 = 15, + 5 de controles = 20.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function cargarActa(string $mesa = '001', array $cambios = []): void
    {
        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => E14Acta::TIPO_CONCEJO,
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'listas' => [
                [
                    'lista_numero' => 11,
                    'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                    'votos_solo_lista' => 2,
                    'total_agrupacion' => 10,
                    'preferentes' => [
                        ['numero' => 1, 'votos' => 5],
                        ['numero' => 5, 'votos' => 3],
                    ],
                ],
                [
                    'lista_numero' => 1,
                    'lista_nombre' => 'PARTIDO LIBERAL COLOMBIANO',
                    'votos_solo_lista' => 1,
                    'total_agrupacion' => 5,
                    'preferentes' => [
                        // El 1 también existe aquí: es otra persona.
                        ['numero' => 1, 'votos' => 4],
                    ],
                ],
                [
                    // Estaba en el tarjetón y no sacó un voto.
                    'lista_numero' => 24,
                    'lista_nombre' => 'PARTIDO DEMÓCRATA COLOMBIANO',
                    'votos_solo_lista' => 0,
                    'total_agrupacion' => 0,
                    'preferentes' => [],
                ],
            ],
            'votos_blanco' => 3,
            'votos_nulos' => 1,
            'votos_no_marcados' => 1,
            'votos_urna' => 20,
            'votantes_e11' => 20,
        ], $cambios))->assertStatus(201);
    }

    private function consolidado(string $tipo = E14Acta::TIPO_CONCEJO)
    {
        return $this->getJson("/api/v1/e14/consolidado?tipo={$tipo}");
    }

    // -------------------------------------------------------- por lista

    public function test_agrega_los_votos_de_cada_agrupacion(): void
    {
        $this->operador();
        $this->cargarActa('001');
        $this->cargarActa('002');

        $this->consolidado()
            ->assertStatus(200)
            // Ordenado por número de lista, como el tarjetón.
            ->assertJsonPath('data.0.lista_numero', 1)
            ->assertJsonPath('data.0.lista_nombre', 'PARTIDO LIBERAL COLOMBIANO')
            ->assertJsonPath('data.0.votos', 10)   // 5 × 2 actas
            ->assertJsonPath('data.1.lista_numero', 11)
            ->assertJsonPath('data.1.votos', 20)   // 10 × 2
            ->assertJsonCount(3, 'data');
    }

    public function test_una_lista_sin_votos_sigue_en_el_consolidado(): void
    {
        $this->operador();
        $this->cargarActa();

        // Omitirla convertiría «cero votos» en «no se presentó», que no es lo
        // mismo para quien lee el consolidado.
        $this->consolidado()
            ->assertStatus(200)
            ->assertJsonPath('data.2.lista_numero', 24)
            ->assertJsonPath('data.2.votos', 0);
    }

    public function test_los_totales_cuentan_las_agrupaciones_y_los_controles(): void
    {
        $this->operador();
        $this->cargarActa('001');
        $this->cargarActa('002');

        $this->consolidado()
            ->assertStatus(200)
            ->assertJsonPath('meta.total_listas', 30)
            ->assertJsonPath('meta.votos_blanco', 6)
            ->assertJsonPath('meta.votos_nulos', 2)
            ->assertJsonPath('meta.votos_no_marcados', 2)
            ->assertJsonPath('meta.total_votos', 40)
            ->assertJsonPath('meta.actas.procesada', 2);
    }

    // ---------------------------------------------------- por preferente

    public function test_agrega_por_lista_y_numero_de_preferencia(): void
    {
        $this->operador();
        $this->cargarActa('001');
        $this->cargarActa('002');

        $respuesta = $this->consolidado()->assertStatus(200);

        // La identidad es (lista, número): el 1 del liberal y el 1 del centro
        // democrático son dos personas y no pueden fundirse.
        $respuesta
            ->assertJsonPath('preferentes.0.lista_numero', 1)
            ->assertJsonPath('preferentes.0.numero', 1)
            ->assertJsonPath('preferentes.0.votos', 8)     // 4 × 2
            ->assertJsonPath('preferentes.1.lista_numero', 11)
            ->assertJsonPath('preferentes.1.numero', 1)
            ->assertJsonPath('preferentes.1.votos', 10)    // 5 × 2
            ->assertJsonPath('preferentes.2.lista_numero', 11)
            ->assertJsonPath('preferentes.2.numero', 5)
            ->assertJsonPath('preferentes.2.votos', 6)
            ->assertJsonCount(3, 'preferentes');
    }

    public function test_el_preferente_no_trae_nombre_inventado(): void
    {
        $this->operador();
        $this->cargarActa();

        $fila = $this->consolidado()->assertStatus(200)->json('preferentes.0');

        // El acta solo trae el número; una columna de nombre vacía invitaría a
        // rellenarla a mano con lo que alguien recuerde.
        $this->assertSame(['lista_numero', 'lista_nombre', 'numero', 'votos'], array_keys($fila));
    }

    public function test_la_suma_de_preferentes_mas_solo_lista_da_el_total_de_la_lista(): void
    {
        $this->operador();
        $this->cargarActa();

        $respuesta = $this->consolidado()->assertStatus(200);

        $deLaOnce = collect($respuesta->json('preferentes'))
            ->where('lista_numero', 11)
            ->sum('votos');

        // 5 + 3 de preferentes + 2 de solo lista = 10, que es su total.
        $this->assertSame(8, $deLaOnce);
        $this->assertSame(10, $respuesta->json('data.1.votos'));
    }

    // --------------------------------------------- solo las que cuadran

    public function test_un_acta_que_no_cuadra_no_entra(): void
    {
        $this->operador();
        $this->cargarActa('001');
        // La segunda tiene una lista que no cuadra: el acta entera queda fuera.
        $this->cargarActa('002', ['listas' => [[
            'lista_numero' => 11,
            'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
            'votos_solo_lista' => 2,
            'total_agrupacion' => 10,
            'preferentes' => [['numero' => 1, 'votos' => 99]],
        ]]]);

        $this->consolidado()
            ->assertStatus(200)
            ->assertJsonPath('meta.total_listas', 15)
            ->assertJsonPath('meta.actas.procesada', 1)
            ->assertJsonPath('meta.actas.inconsistente', 1);
    }

    // ------------------------------------------- el uninominal no cambia

    public function test_el_consolidado_de_alcaldia_sigue_igual(): void
    {
        // Aquí sí, una campaña de alcaldía: el consolidado uninominal es el de
        // quien se presenta a la alcaldía (0093).
        $this->operador(tenant: Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']));

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'estado' => 'procesada',
            'zona' => '01', 'puesto' => '01', 'mesa' => '001',
            'suma_declarada' => 20, 'votos_urna' => 20, 'votantes_e11' => 20,
            'votos_blanco' => 3, 'votos_nulos' => 1, 'votos_no_marcados' => 1,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR', 'votos' => 10],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 5],
            ],
        ])->assertStatus(201);

        $this->consolidado(E14Acta::TIPO_ALCALDIA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.numero', 1)
            ->assertJsonPath('data.0.nombre', 'JORGE BOLIVAR')
            ->assertJsonPath('data.0.votos', 10)
            ->assertJsonPath('meta.total_candidatos', 15)
            ->assertJsonPath('meta.total_votos', 20)
            // Un uninominal no tiene el bloque de preferentes.
            ->assertJsonMissingPath('preferentes');
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_verlo_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->consolidado()->assertStatus(403);
    }

    public function test_no_suma_las_actas_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $this->cargarActa('001');

        $this->operador();
        $this->cargarActa('001');

        $this->consolidado()
            ->assertStatus(200)
            ->assertJsonPath('meta.total_listas', 15)
            ->assertJsonPath('meta.actas.procesada', 1);
    }
}

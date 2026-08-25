<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * El esquema después de meter la cola en medio (Spec 0071).
 *
 * Un acta ahora existe antes de leerse: se sube un PDF y de él todavía no se
 * sabe ni de qué mesa es. Lo que se prueba aquí es que esa etapa cabe en la
 * tabla sin aflojar lo que protegía al escrutinio ya leído.
 */
class E14EsquemaColaTest extends TestCase
{
    private function evento(Tenant $tenant): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'nombre' => 'Alcaldia',
            'tipo' => 'alcaldia',
        ]);
    }

    public function test_un_acta_recien_subida_no_necesita_saber_de_que_mesa_es(): void
    {
        $tenant = Tenant::factory()->create();

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $this->evento($tenant)->id,
            'tipo' => 'alcaldia',
            'archivo_nombre' => 'mesa.pdf',
            'archivo_hash' => str_repeat('a', 64),
            'archivo_path' => 'e14/1/aaa.pdf',
            'upload_batch_id' => '11111111-1111-4111-8111-111111111111',
        ]);

        $this->assertNull($acta->zona);
        $this->assertNull($acta->mesa);
        $this->assertSame('cargada', $acta->fresh()->estado, 'Un acta nace cargada.');
        $this->assertFalse($acta->fueLeida());
    }

    public function test_muchas_actas_sin_leer_conviven_bajo_el_indice_unico_de_mesa(): void
    {
        $tenant = Tenant::factory()->create();
        $evento = $this->evento($tenant);

        foreach (range(1, 3) as $n) {
            E14Acta::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'electoral_event_id' => $evento->id,
                'tipo' => 'alcaldia',
                'archivo_hash' => str_repeat((string) $n, 64),
                'archivo_path' => "e14/1/{$n}.pdf",
            ]);
        }

        // El único por (tenant, evento, tipo, zona, puesto, mesa) sigue declarado;
        // varios nulos no colisionan entre sí. La unicidad empieza a aplicar
        // cuando el worker dice de qué mesa era cada una.
        $this->assertSame(3, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    /**
     * Cada elección con la campaña que la escruta (Spec 0093).
     *
     * Un tenant sirve a **una** elección, así que los cinco tipos ya no caben
     * en la misma campaña: lo que se prueba es que la ingesta acepta las cinco
     * formas del papel, una por campaña, no que una campaña pueda con todas.
     *
     * @var array<string, string>
     */
    private const CARGO_DE = [
        E14Acta::TIPO_ALCALDIA => 'Alcaldia',
        E14Acta::TIPO_GOBERNACION => 'Gobernacion',
        E14Acta::TIPO_CONCEJO => 'Concejo',
        E14Acta::TIPO_SENADO => 'Congresista',
        E14Acta::TIPO_ASAMBLEA => 'Diputado',
    ];

    public function test_la_ingesta_directa_acepta_los_cinco_tipos(): void
    {
        foreach (E14Acta::TIPOS as $indice => $tipo) {
            $tenant = Tenant::factory()->create(['tipo_cargo' => self::CARGO_DE[$tipo]]);
            [$user, $token] = $this->createTenantWithUser(
                [Permissions::VIEW_E14, Permissions::MANAGE_E14],
                $tenant
            );
            $this->actingAsTenantUser($user, $token);

            // Cada familia trae su forma (Spec 0067): el uninominal, candidatos
            // sueltos y su suma declarada; la corporación, agrupaciones —y sin
            // suma declarada, que en su papel no existe.
            $resultado = E14Acta::esCorporacion($tipo)
                ? ['listas' => [[
                    'lista_numero' => 11,
                    'lista_nombre' => 'PARTIDO X',
                    'votos_solo_lista' => 4,
                    'total_agrupacion' => 10,
                    'preferentes' => [['numero' => 1, 'votos' => 6]],
                ]]]
                : [
                    'suma_declarada' => 10,
                    'resultados' => [['numero' => 1, 'nombre' => 'X', 'votos' => 10]],
                ];

            $this->postJson('/api/v1/e14/actas', [
                'tipo' => $tipo,
                'zona' => '01',
                'puesto' => '01',
                'mesa' => str_pad((string) $indice, 3, '0', STR_PAD_LEFT),
                'votos_urna' => 10,
                'votantes_e11' => 10,
                ...$resultado,
            ])->assertStatus(201)
                ->assertJsonPath('data.tipo', $tipo)
                ->assertJsonPath('data.estado', 'procesada');
        }
    }

    public function test_un_tipo_de_eleccion_que_no_existe_se_rechaza(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([Permissions::MANAGE_E14], $tenant);
        $this->actingAsTenantUser($user, $token);

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => 'presidencia',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 10,
            'votos_urna' => 10,
            'resultados' => [['numero' => 1, 'votos' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('tipo');
    }

    public function test_el_cliente_no_puede_declarar_un_estado_de_la_cola(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([Permissions::MANAGE_E14], $tenant);
        $this->actingAsTenantUser($user, $token);

        // La cola la mueve el servidor. Si un cliente pudiera declararse
        // `procesando`, podría sacar un acta de la cola sin haberla leído.
        foreach (['cargada', 'pendiente', 'procesando'] as $estado) {
            $this->postJson('/api/v1/e14/actas', [
                'tipo' => 'alcaldia',
                'zona' => '01',
                'puesto' => '01',
                'mesa' => '001',
                'estado' => $estado,
                'suma_declarada' => 10,
                'votos_urna' => 10,
                'resultados' => [['numero' => 1, 'votos' => 10]],
            ])->assertStatus(422)->assertJsonValidationErrors('estado');
        }
    }
}

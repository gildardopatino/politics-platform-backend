<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un acta sin datos no puede quedar cuadrada (Spec 0077 · RF-6).
 *
 * `0 = 0 = 0` satisface las dos igualdades del cuadre —casillas = declarada =
 * urna—, así que un acta que el lector no consiguió transcribir salía
 * **procesada** y entraba al consolidado aportando nada. Peor que un error de
 * suma: el acta desaparecía de la cola de revisión, y quien miraba el panel veía
 * una mesa escrutada donde no había un solo voto leído.
 *
 * El guardia va **antes** de las comparaciones y por los **tres** caminos que
 * usan `evaluar()`, porque los tres escriben el mismo campo `estado`: la ingesta
 * directa del lector local (0061), el resultado del worker (0071) y la
 * corrección manual del panel. Cerrar solo uno dejaría las otras dos puertas
 * abiertas a lo mismo.
 */
class E14ActaSinDatosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14], ?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function pendiente(Tenant $tenant, string $semilla = 'a'): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
        ]);

        $hash = hash('sha256', "{$tenant->id}-{$semilla}");

        Storage::disk(config('e14.disk'))->put("e14/{$tenant->id}/{$hash}.pdf", '%PDF-1.4');

        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => 'alcaldia',
            'archivo_nombre' => "acta-{$semilla}.pdf",
            'archivo_hash' => $hash,
            'archivo_path' => "e14/{$tenant->id}/{$hash}.pdf",
            'estado' => E14Acta::ESTADO_PENDIENTE,
        ]);
    }

    /**
     * Una lectura en blanco: el lector devolvió los candidatos del tarjetón pero
     * no consiguió leer un solo número, y todas las cifras vienen en 0.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function enBlanco(array $cambios = []): array
    {
        return array_replace([
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 0,
            'votos_urna' => 0,
            'votantes_e11' => 0,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 0],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 0],
            ],
        ], $cambios);
    }

    // ------------------------------------------------ camino 1: worker (0071)

    public function test_el_worker_no_puede_dejar_procesada_un_acta_que_leyo_en_blanco(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->enBlanco())
            ->assertStatus(200)
            // El worker dijo `procesada`; el servidor rehace la cuenta y manda
            // el acta a la cola de revisión, que es donde alguien puede hacer
            // algo con ella.
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.suma_calculada', 0);

        $acta->refresh();

        $this->assertSame(E14Acta::ESTADO_REVISION_MANUAL, $acta->estado);
        $this->assertStringContainsString('no tiene datos', $acta->observacion);
        $this->assertFalse($acta->cuadra());
    }

    public function test_el_acta_en_blanco_no_entra_al_consolidado(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->enBlanco())
            ->assertStatus(200);

        // El consolidado solo suma actas `procesada`: es la consecuencia que de
        // verdad importaba del bug. El acta existe, pero está en revisión.
        $this->getJson('/api/v1/e14/consolidado')
            ->assertStatus(200)
            ->assertJsonPath('meta.actas.procesada', 0)
            ->assertJsonPath('meta.actas.revision_manual', 1);
    }

    public function test_una_lectura_con_un_solo_voto_sigue_cuadrando_como_siempre(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Una mesa de verdad puede tener un único voto; eso no es un acta vacía.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->enBlanco([
            'suma_declarada' => 1,
            'votos_urna' => 1,
            'resultados' => [['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 1]],
        ]))
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.observacion', null);
    }

    // --------------------------------------- camino 2: ingesta directa (0061)

    public function test_la_ingesta_directa_tampoco_registra_un_acta_vacia_como_procesada(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas', $this->enBlanco([
            'tipo' => 'alcaldia',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'revision_manual');

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        $this->assertStringContainsString('no tiene datos', $acta->observacion);
    }

    public function test_el_acta_que_el_lector_declara_ilegible_conserva_su_propia_explicacion(): void
    {
        $this->operador();

        // Los dos la mandan a revisión, así que no hay discrepancia que
        // resolver: entre «sin datos» y «la casilla está tachada», la que dice
        // dónde mirar es la del lector.
        $this->postJson('/api/v1/e14/actas', $this->enBlanco([
            'tipo' => 'alcaldia',
            'estado' => 'revision_manual',
            'resultados' => [],
            'observacion' => 'la casilla del candidato 3 está tachada',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.observacion', 'la casilla del candidato 3 está tachada');
    }

    // ---------------------------------------- camino 3: corrección manual

    public function test_una_correccion_que_deja_el_acta_en_cero_la_devuelve_a_revision(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Parte de un acta que sí cuadraba…
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->enBlanco([
            'suma_declarada' => 10,
            'votos_urna' => 10,
            'resultados' => [['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 10]],
        ]))->assertJsonPath('data.estado', 'procesada');

        // …y alguien la vacía a mano. Borrar las cifras no es aprobar el acta:
        // sin votos no hay escrutinio que consolidar.
        $this->putJson("/api/v1/e14/actas/{$acta->id}", [
            'suma_declarada' => 0,
            'votos_urna' => 0,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 0]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual');

        $this->assertStringContainsString('no tiene datos', $acta->fresh()->observacion);
    }

    public function test_una_correccion_con_votos_sigue_pudiendo_cuadrar_el_acta(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->enBlanco())
            ->assertJsonPath('data.estado', 'revision_manual');

        // Quien mira el papel captura el acta entera: el guardia deja de aplicar
        // y vuelven las reglas de coincidencia de siempre.
        $this->putJson("/api/v1/e14/actas/{$acta->id}", [
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 69],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.observacion', null);
    }
}

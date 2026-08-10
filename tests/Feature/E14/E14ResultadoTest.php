<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lo que el worker devuelve de un acta que reclamó (Spec 0071).
 *
 * Aquí se cierra el circuito: el acta entró como un PDF sin identidad y sale
 * como el escrutinio de una mesa concreta. El servidor rehace la cuenta antes
 * de aceptarla, igual que en la ingesta directa de la 0061.
 */
class E14ResultadoTest extends TestCase
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

    private function pendiente(Tenant $tenant, string $semilla = 'a', string $tipo = 'alcaldia'): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => ucfirst($tipo),
        ]);

        $hash = hash('sha256', "{$tenant->id}-{$semilla}");

        Storage::disk(config('e14.disk'))->put("e14/{$tenant->id}/{$hash}.pdf", '%PDF-1.4');

        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => $tipo,
            'archivo_nombre' => "acta-{$semilla}.pdf",
            'archivo_hash' => $hash,
            'archivo_path' => "e14/{$tenant->id}/{$hash}.pdf",
            'estado' => E14Acta::ESTADO_PENDIENTE,
        ]);
    }

    /**
     * Lectura que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function lectura(array $cambios = []): array
    {
        return array_replace([
            'estado' => 'procesada',
            'departamento_code' => '73',
            'municipio_code' => '73001',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'lugar' => 'INSTITUCION EDUCATIVA',
            'fuente' => 'vision',
            'suma_calculada' => 111,
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
        ], $cambios);
    }

    public function test_una_lectura_que_cuadra_deja_el_acta_procesada(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.mesa', '001')
            ->assertJsonPath('data.suma_calculada', 111)
            ->assertJsonPath('data.observacion', null);

        $this->assertSame(3, E14Resultado::withoutGlobalScope(TenantScope::class)->count());
        $this->assertNull($acta->fresh()->claimed_at, 'El acta deja de estar reclamada.');
    }

    public function test_el_servidor_rehace_la_cuenta_y_no_cree_al_worker(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // El worker dice `procesada`, pero sus casillas suman 121 contra 111
        // declarados.
        $respuesta = $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]));

        $respuesta->assertStatus(200)->assertJsonPath('data.estado', 'inconsistente');

        $this->assertStringContainsString('121', $respuesta->json('data.observacion'));
    }

    public function test_cuando_el_worker_no_pudo_leer_el_acta_va_a_revision(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", [
            'estado' => 'revision_manual',
            'observacion' => 'la casilla del candidato 3 está tachada',
        ])->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.observacion', 'la casilla del candidato 3 está tachada');

        $this->assertNull($acta->fresh()->claimed_at, 'Deja de contar como reclamada.');
    }

    public function test_publicar_dos_veces_el_mismo_resultado_no_cambia_nada(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Un worker que publica y se cae antes de leer la respuesta reintenta.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())->assertStatus(200);
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(1, E14Acta::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(3, E14Resultado::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_dos_escaneos_de_la_misma_mesa_no_se_suman_dos_veces(): void
    {
        $tenant = $this->operador();
        $primera = $this->pendiente($tenant, 'a');
        $segunda = $this->pendiente($tenant, 'b');

        $this->postJson("/api/v1/e14/actas/{$primera->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        // Dos fotos distintas del mismo papel: los hashes no coinciden, así que
        // la deduplicación de la carga no las vio. Decide una persona cuál vale;
        // mientras tanto la cola sigue y el consolidado no cuenta doble.
        $this->postJson("/api/v1/e14/actas/{$segunda->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual');

        $this->assertStringContainsString(
            (string) $primera->id,
            $segunda->fresh()->observacion
        );

        $this->getJson('/api/v1/e14/consolidado')->assertJsonPath('meta.total_votos', 111);
    }

    public function test_una_lectura_legible_tiene_que_decir_de_que_mesa_es(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura(['mesa' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mesa');
    }

    public function test_el_worker_no_puede_declarar_un_estado_de_la_cola(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura(['estado' => 'pendiente']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_el_acta_leida_entra_al_consolidado_y_al_resumen(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())->assertStatus(200);

        $this->getJson('/api/v1/e14/consolidado')
            ->assertJsonPath('meta.actas.procesada', 1)
            ->assertJsonPath('meta.total_candidatos', 99);

        $this->getJson('/api/v1/e14/resumen')
            ->assertJsonPath('data.por_estado.procesada', 1)
            ->assertJsonPath('data.en_cola', 0);
    }

    public function test_un_acta_de_otra_campana_no_se_puede_resolver(): void
    {
        $ajena = Tenant::factory()->create();
        $acta = $this->pendiente($ajena);

        $this->operador();

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(404);

        $this->assertSame('pendiente', $acta->fresh()->estado);
    }

    public function test_publicar_un_resultado_exige_permiso_de_escritura(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(403);

        $this->assertSame('pendiente', $acta->fresh()->estado);
    }

    public function test_la_correccion_manual_sigue_mandando_sobre_lo_que_leyo_el_worker(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ]))->assertJsonPath('data.estado', 'inconsistente');

        // Alguien mira el papel desde el panel: el 1 tenía 50.
        $this->putJson("/api/v1/e14/actas/{$acta->id}", [
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.fuente', 'manual');
    }
}

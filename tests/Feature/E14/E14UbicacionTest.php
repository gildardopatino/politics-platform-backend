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
 * La ubicación completa de la mesa (Spec 0074).
 *
 * El encabezado del E-14 trae departamento y municipio **con código y nombre**, y
 * el nombre del puesto (`lugar`). El lector ya lo transcribía todo, pero el
 * backend solo guardaba los códigos: sin los nombres nadie puede leer «29 - 001»
 * y sin el lugar no se puede agrupar el escrutinio por puesto de votación.
 *
 * Es metadato: entra, se muestra y se filtra, pero **no toca el cuadre**.
 */
class E14UbicacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

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

    /** Lectura que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111. */
    private function lectura(array $cambios = []): array
    {
        return array_replace([
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
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

    /** El encabezado tal como sale impreso: «29 - TOLIMA», «001 - IBAGUE». */
    private function ubicacion(array $cambios = []): array
    {
        return array_replace([
            'departamento_code' => '29',
            'departamento' => 'TOLIMA',
            'municipio_code' => '001',
            'municipio' => 'IBAGUE',
            'lugar' => 'UNIVERSIDAD COOPERATIVA NUEVA SEDE',
        ], $cambios);
    }

    // --------------------------------------------------------- se persiste

    public function test_el_worker_guarda_la_ubicacion_completa(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            $this->lectura($this->ubicacion())
        )->assertStatus(200)
            ->assertJsonPath('data.departamento_code', '29')
            ->assertJsonPath('data.departamento', 'TOLIMA')
            ->assertJsonPath('data.municipio_code', '001')
            ->assertJsonPath('data.municipio', 'IBAGUE')
            ->assertJsonPath('data.lugar', 'UNIVERSIDAD COOPERATIVA NUEVA SEDE')
            ->assertJsonPath('data.zona', '01')
            ->assertJsonPath('data.puesto', '01')
            ->assertJsonPath('data.mesa', '001');

        $this->assertDatabaseHas('e14_actas', [
            'id' => $acta->id,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'UNIVERSIDAD COOPERATIVA NUEVA SEDE',
        ]);
    }

    public function test_la_ingesta_directa_tambien_guarda_los_nombres(): void
    {
        $this->operador();

        // El lector de carpeta local lee el mismo encabezado que el worker.
        $this->postJson('/api/v1/e14/actas', array_replace(
            $this->lectura($this->ubicacion()),
            ['tipo' => 'alcaldia']
        ))->assertStatus(201)
            ->assertJsonPath('data.departamento', 'TOLIMA')
            ->assertJsonPath('data.municipio', 'IBAGUE')
            ->assertJsonPath('data.lugar', 'UNIVERSIDAD COOPERATIVA NUEVA SEDE');
    }

    public function test_quien_revisa_puede_corregir_la_ubicacion(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura($this->ubicacion()));

        // La visión leyó mal el nombre del puesto; quien mira el papel lo arregla.
        $this->putJson("/api/v1/e14/actas/{$acta->id}", [
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUÉ',
            'lugar' => 'COLEGIO SAN SIMON',
        ])->assertStatus(200)
            ->assertJsonPath('data.municipio', 'IBAGUÉ')
            ->assertJsonPath('data.lugar', 'COLEGIO SAN SIMON')
            // Corregir metadato no cambia el veredicto de las cifras.
            ->assertJsonPath('data.estado', 'procesada');
    }

    public function test_la_ubicacion_no_decide_si_el_acta_cuadra(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Sin nombre de departamento ni lugar el acta cuadra igual: es metadato.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.departamento', null)
            ->assertJsonPath('data.municipio', null)
            ->assertJsonPath('data.lugar', null);
    }

    public function test_un_acta_ilegible_conserva_la_ubicacion_que_si_se_leyo(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // El encabezado va impreso y se lee casi siempre; las casillas son las
        // que se tuercen. Perder el encabezado por eso dejaría al revisor sin
        // saber siquiera de qué puesto es el papel que tiene delante.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", array_replace(
            $this->ubicacion(),
            [
                'estado' => 'revision_manual',
                'zona' => '01',
                'puesto' => '01',
                'mesa' => '001',
                'observacion' => 'las casillas están tachadas',
            ]
        ))->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.departamento', 'TOLIMA')
            ->assertJsonPath('data.lugar', 'UNIVERSIDAD COOPERATIVA NUEVA SEDE');
    }

    // ----------------------------------------------------------- se filtra

    /** Dos actas en municipios distintos del mismo departamento. */
    private function dosActas(Tenant $tenant): void
    {
        $primera = $this->pendiente($tenant, 'a');
        $segunda = $this->pendiente($tenant, 'b');

        $this->postJson("/api/v1/e14/actas/{$primera->id}/resultado", $this->lectura($this->ubicacion()));

        $this->postJson("/api/v1/e14/actas/{$segunda->id}/resultado", $this->lectura(array_replace(
            $this->ubicacion([
                'municipio_code' => '024',
                'municipio' => 'ARMERO',
                'lugar' => 'COLEGIO SAN SIMON',
            ]),
            ['mesa' => '002']
        )));
    }

    public function test_el_panel_filtra_por_codigo_de_departamento_y_municipio(): void
    {
        $tenant = $this->operador();
        $this->dosActas($tenant);

        $this->getJson('/api/v1/e14/actas?departamento_code=29')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/e14/actas?municipio_code=024')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.municipio', 'ARMERO');

        $this->getJson('/api/v1/e14/actas?departamento_code=05')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_el_lugar_se_busca_por_coincidencia_parcial(): void
    {
        $tenant = $this->operador();
        $this->dosActas($tenant);

        // Nadie escribe «UNIVERSIDAD COOPERATIVA NUEVA SEDE» entero para buscarla.
        $this->getJson('/api/v1/e14/actas?lugar=cooperativa')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lugar', 'UNIVERSIDAD COOPERATIVA NUEVA SEDE');

        $this->getJson('/api/v1/e14/actas?lugar=SAN SIMON')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lugar', 'COLEGIO SAN SIMON');
    }

    public function test_departamento_y_municipio_se_buscan_por_codigo_o_por_nombre(): void
    {
        $tenant = $this->operador();
        $this->dosActas($tenant);

        // El panel tiene una sola casilla por eje: quien busca escribe lo que
        // recuerda, el código o el nombre.
        $this->getJson('/api/v1/e14/actas?departamento=tolima')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/e14/actas?departamento=29')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/e14/actas?municipio=armero')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/e14/actas?municipio=024')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_los_filtros_de_ubicacion_no_cruzan_tenants(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $this->dosActas($ajeno);

        $propio = $this->operador();
        $acta = $this->pendiente($propio, 'c');
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura($this->ubicacion()));

        // El mismo departamento existe en las dos campañas; cada una ve la suya.
        $this->getJson('/api/v1/e14/actas?departamento_code=29')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    // -------------------------------------------------------- se desglosa

    public function test_el_consolidado_se_desglosa_por_lugar(): void
    {
        $tenant = $this->operador();
        $this->dosActas($tenant);

        $respuesta = $this->getJson('/api/v1/e14/consolidado')->assertStatus(200);

        $respuesta->assertJsonPath('desglose.por_lugar.0.lugar', 'COLEGIO SAN SIMON')
            ->assertJsonPath('desglose.por_lugar.0.total', 99)
            ->assertJsonPath('desglose.por_lugar.1.lugar', 'UNIVERSIDAD COOPERATIVA NUEVA SEDE')
            ->assertJsonPath('desglose.por_lugar.1.total', 99);
    }
}

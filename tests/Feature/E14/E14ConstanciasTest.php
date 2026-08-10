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
 * Las constancias de los jurados (Spec 0073).
 *
 * La página 2 del acta es donde los jurados explican a mano lo que pasó en la
 * mesa, y casi siempre es el contexto que le falta a quien revisa una que no
 * cuadra. Se guarda tal cual, se muestra, y **no toca el cuadre**: un acta no
 * cuadra menos porque alguien escriba por qué no cuadra.
 */
class E14ConstanciasTest extends TestCase
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

    private const TEXTO = 'Se recontaron los votos de la mesa por diferencia de un tarjetón.';

    private function constancias(array $cambios = []): array
    {
        return array_replace([
            'hubo_recuento' => true,
            'constancias' => self::TEXTO,
            'recuento_solicitado_por' => 'CARLOS PEREZ',
            'recuento_representacion' => 'PARTIDO VERDE',
        ], $cambios);
    }

    // ------------------------------------------------- al publicar el resultado

    public function test_el_worker_guarda_lo_que_escribieron_los_jurados(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            $this->lectura($this->constancias())
        )->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.hubo_recuento', true)
            ->assertJsonPath('data.constancias', self::TEXTO)
            ->assertJsonPath('data.recuento_solicitado_por', 'CARLOS PEREZ')
            ->assertJsonPath('data.recuento_representacion', 'PARTIDO VERDE')
            ->assertJsonPath('data.tiene_constancias', true);
    }

    public function test_las_constancias_no_deciden_si_el_acta_cuadra(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Las casillas suman 121 contra 111 declarados, y los jurados explican
        // por qué. La explicación no arregla la aritmética.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura(
            $this->constancias([
                'resultados' => [
                    ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 60],
                    ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                    ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
                ],
            ])
        ))->assertStatus(200)
            ->assertJsonPath('data.estado', 'inconsistente')
            ->assertJsonPath('data.constancias', self::TEXTO);
    }

    public function test_un_acta_que_cuadra_con_constancias_sigue_cuadrando(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            $this->lectura($this->constancias())
        )->assertJsonPath('data.estado', 'procesada');

        // Y entra al consolidado como cualquier otra.
        $this->getJson('/api/v1/e14/consolidado')->assertJsonPath('meta.actas.procesada', 1);
    }

    public function test_si_los_votos_no_se_leyeron_pero_las_constancias_si_se_guardan_igual(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        // Es el caso que más importa: el revisor abre un acta ilegible y al
        // menos tiene delante lo que los jurados escribieron.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", array_replace(
            $this->constancias(),
            [
                'estado' => 'revision_manual',
                'observacion' => 'las casillas están tachadas',
            ]
        ))->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.constancias', self::TEXTO)
            ->assertJsonPath('data.hubo_recuento', true);
    }

    public function test_una_mesa_duplicada_tambien_conserva_sus_constancias(): void
    {
        $tenant = $this->operador();
        $primera = $this->pendiente($tenant, 'a');
        $segunda = $this->pendiente($tenant, 'b');

        $this->postJson("/api/v1/e14/actas/{$primera->id}/resultado", $this->lectura())
            ->assertJsonPath('data.estado', 'procesada');

        $this->postJson(
            "/api/v1/e14/actas/{$segunda->id}/resultado",
            $this->lectura($this->constancias())
        )->assertStatus(200)
            ->assertJsonPath('data.estado', 'revision_manual')
            ->assertJsonPath('data.constancias', self::TEXTO);
    }

    public function test_sin_constancias_el_acta_no_las_inventa(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura())
            ->assertStatus(200)
            ->assertJsonPath('data.hubo_recuento', null)
            ->assertJsonPath('data.constancias', null)
            ->assertJsonPath('data.tiene_constancias', false);
    }

    public function test_un_recuento_que_no_se_pudo_leer_no_es_un_no(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'hubo_recuento' => null,
            'constancias' => 'ilegible',
        ]))->assertStatus(200)
            ->assertJsonPath('data.hubo_recuento', null);

        // Tres estados, no dos: sí, no y «no se sabe».
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'hubo_recuento' => false,
        ]))->assertJsonPath('data.hubo_recuento', false);
    }

    public function test_un_recuento_que_no_es_booleano_se_rechaza(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'hubo_recuento' => 'quizás',
        ]))->assertStatus(422)->assertJsonValidationErrors('hubo_recuento');
    }

    // ---------------------------------------------------- en revisión manual

    public function test_quien_revisa_puede_completar_las_constancias(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura());

        // La visión no acertó con la letra; la persona que mira el papel sí.
        $this->putJson("/api/v1/e14/actas/{$acta->id}", [
            'hubo_recuento' => true,
            'constancias' => 'Los jurados anotaron que se anuló un tarjetón.',
            'recuento_solicitado_por' => 'ANA GOMEZ',
        ])->assertStatus(200)
            ->assertJsonPath('data.hubo_recuento', true)
            ->assertJsonPath('data.constancias', 'Los jurados anotaron que se anuló un tarjetón.')
            ->assertJsonPath('data.recuento_solicitado_por', 'ANA GOMEZ')
            // Corregir el texto no cambia el veredicto de las cifras.
            ->assertJsonPath('data.estado', 'procesada');
    }

    public function test_corregir_solo_las_constancias_no_toca_las_cifras(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura([
            'votos_urna' => 110,
        ]))->assertJsonPath('data.estado', 'inconsistente');

        $this->putJson("/api/v1/e14/actas/{$acta->id}", ['constancias' => 'Faltó un votante.'])
            ->assertStatus(200)
            ->assertJsonPath('data.constancias', 'Faltó un votante.')
            ->assertJsonPath('data.estado', 'inconsistente')
            ->assertJsonPath('data.votos_urna', 110);
    }

    public function test_se_pueden_borrar_unas_constancias_mal_transcritas(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->postJson(
            "/api/v1/e14/actas/{$acta->id}/resultado",
            $this->lectura($this->constancias())
        );

        $this->putJson("/api/v1/e14/actas/{$acta->id}", ['constancias' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.constancias', null);
    }

    // --------------------------------------------------------- ingesta directa

    public function test_el_lector_de_carpeta_local_tambien_las_puede_mandar(): void
    {
        $this->operador();

        // Lee la misma página 2 que el worker; perderlas según por qué puerta
        // entre el acta sería una asimetría que nadie recordaría después.
        $this->postJson('/api/v1/e14/actas', array_replace(
            $this->lectura($this->constancias()),
            ['tipo' => 'alcaldia']
        ))->assertStatus(201)
            ->assertJsonPath('data.constancias', self::TEXTO)
            ->assertJsonPath('data.hubo_recuento', true)
            ->assertJsonPath('data.estado', 'procesada');
    }
}

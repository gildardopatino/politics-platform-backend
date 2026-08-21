<?php

namespace Tests\Feature\Meetings;

use App\Models\Meeting;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El backfill del QR de las reuniones (Spec 0087).
 *
 * El QR y el formulario público de check-in solo aparecen si la reunión tiene
 * `qr_code`: el recurso deriva el SVG **bajo demanda** de ese código y el panel
 * condiciona los dos botones a que llegue. Pero el código lo generaba **solo**
 * el `store()` de la API, así que toda reunión sembrada —factory, seeders,
 * `migrate:fresh --seed`— nacía sin él y el organizador se quedaba sin QR que
 * imprimir aunque el circuito de check-in funcionara perfecto.
 *
 * No es un bug de render ni del generador: es un hueco de datos, y por eso se
 * cierra por los dos lados —las que ya existen (backfill) y las que vengan
 * (guard en la factory)— sin tocar el frontend ni el `store()`.
 */
class MeetingsBackfillQrTest extends TestCase
{
    protected function tearDown(): void
    {
        // El servicio de QR escribe el SVG de respaldo en disco, no por Storage:
        // lo que la prueba ensucia, la prueba lo limpia.
        File::deleteDirectory(storage_path('app/public/qr-codes'));

        parent::tearDown();
    }

    /**
     * Una reunión sin QR: la que sembraban los seeders antes de la 0087.
     *
     * El nulo explícito manda sobre el de la factory, que es justo la puerta que
     * necesitan esta prueba y la que caracteriza el 404 de `GET /qr-code`.
     */
    private function reunionSinQr(Tenant $tenant): Meeting
    {
        return Meeting::factory()->forTenant($tenant)->create(['qr_code' => null]);
    }

    private function conQr(int $id): ?string
    {
        return Meeting::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->whereKey($id)
            ->value('qr_code');
    }

    // ------------------------------------------------------------ backfill

    public function test_el_backfill_llena_los_qr_nulos_de_todos_los_tenants(): void
    {
        $una = Tenant::factory()->create(['slug' => 'campana-una']);
        $otra = Tenant::factory()->create(['slug' => 'campana-otra']);

        $sinQr = [
            $this->reunionSinQr($una)->id,
            $this->reunionSinQr($una)->id,
            $this->reunionSinQr($otra)->id,
        ];

        $this->artisan('meetings:backfill-qr')
            ->expectsOutputToContain('3 reunión(es)')
            ->assertExitCode(0);

        foreach ($sinQr as $id) {
            $this->assertNotNull($this->conQr($id), "La reunión #{$id} se quedó sin QR.");
        }
    }

    public function test_el_qr_backfilleado_llega_al_panel_como_qr_data(): void
    {
        $tenant = Tenant::factory()->create();
        $reunion = $this->reunionSinQr($tenant);

        $this->artisan('meetings:backfill-qr')->assertExitCode(0);

        [$user] = $this->createTenantWithUser(['view_meetings'], $tenant);

        $respuesta = $this->actingAsTenantUser($user)
            ->getJson("/api/v1/meetings/{$reunion->id}")
            ->assertStatus(200);

        // Es la condición exacta que mira el panel para pintar el botón.
        $this->assertNotNull($respuesta->json('data.qr_data'));
        $this->assertSame($this->conQr($reunion->id), $respuesta->json('data.qr_data.code'));
    }

    public function test_una_segunda_corrida_no_toca_ninguna_reunion(): void
    {
        $tenant = Tenant::factory()->create();
        $reunion = $this->reunionSinQr($tenant);

        $this->artisan('meetings:backfill-qr')->assertExitCode(0);
        $codigo = $this->conQr($reunion->id);

        $this->artisan('meetings:backfill-qr')
            ->expectsOutputToContain('0 reunión(es)')
            ->assertExitCode(0);

        // Idempotente de verdad: el código no se regenera ni se pisa.
        $this->assertSame($codigo, $this->conQr($reunion->id));
    }

    public function test_el_dry_run_cuenta_y_no_escribe(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-seca']);
        $reunion = $this->reunionSinQr($tenant);

        $this->artisan('meetings:backfill-qr', ['--dry-run' => true])
            ->expectsOutputToContain('campana-seca')
            ->expectsOutputToContain('1 reunión(es)')
            ->assertExitCode(0);

        $this->assertNull($this->conQr($reunion->id), 'El ensayo escribió en la base.');
    }

    // ------------------------------------------------------- multi-tenant

    public function test_cada_qr_se_genera_con_el_slug_de_su_campana(): void
    {
        $una = Tenant::factory()->create(['slug' => 'campana-una']);
        $otra = Tenant::factory()->create(['slug' => 'campana-otra']);

        $deUna = $this->reunionSinQr($una);
        $deOtra = $this->reunionSinQr($otra);

        $this->artisan('meetings:backfill-qr')->assertExitCode(0);

        // Cada SVG de respaldo cae en la carpeta de **su** campaña.
        $this->assertFileExists(storage_path(
            "app/public/qr-codes/campana-una/meeting-{$deUna->id}-{$this->conQr($deUna->id)}.svg"
        ));
        $this->assertFileExists(storage_path(
            "app/public/qr-codes/campana-otra/meeting-{$deOtra->id}-{$this->conQr($deOtra->id)}.svg"
        ));

        // Y ninguna reunión cambió de dueño por el camino.
        $this->assertSame($una->id, Meeting::withoutGlobalScope(TenantScope::class)
            ->whereKey($deUna->id)->value('tenant_id'));
        $this->assertNotSame($this->conQr($deUna->id), $this->conQr($deOtra->id));
    }

    // ------------------------------------------------------------- bordes

    public function test_no_backfillea_una_reunion_borrada(): void
    {
        $tenant = Tenant::factory()->create();
        $reunion = $this->reunionSinQr($tenant);
        $reunion->delete();

        $this->artisan('meetings:backfill-qr')
            ->expectsOutputToContain('0 reunión(es)')
            ->assertExitCode(0);

        // Una reunión en la papelera no tiene formulario que abrir.
        $this->assertNull($this->conQr($reunion->id));
    }

    public function test_una_reunion_huerfana_se_reporta_y_no_aborta_el_lote(): void
    {
        $huerfana = Tenant::factory()->create(['slug' => 'campana-que-se-fue']);
        $viva = Tenant::factory()->create(['slug' => 'campana-viva']);

        $sinDuena = $this->reunionSinQr($huerfana);
        $laBuena = $this->reunionSinQr($viva);

        // La campaña se va a la papelera: su reunión se queda sin slug con el
        // que nombrar el QR, y sin dueño no hay QR que generar.
        $huerfana->delete();

        $this->artisan('meetings:backfill-qr')
            ->expectsOutputToContain('sin campaña')
            ->assertExitCode(0);

        $this->assertNull($this->conQr($sinDuena->id));
        // Lo importante: la huérfana no se llevó por delante al resto del lote.
        $this->assertNotNull($this->conQr($laBuena->id));
    }

    // ------------------------------------------------------- sin regresión

    public function test_el_store_de_la_api_sigue_generando_su_qr_una_sola_vez(): void
    {
        $tenant = Tenant::factory()->create([
            'hierarchy_mode' => 'disabled',
            'require_hierarchy_config' => false,
        ]);
        [$user] = $this->createTenantWithUser(['create_meetings'], $tenant);

        $id = $this->actingAsTenantUser($user)->postJson('/api/v1/meetings', [
            'title' => 'Reunión de barrio',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'planner_user_id' => $user->id,
            'lugar_nombre' => 'Salón comunal',
        ])->assertStatus(201)->json('data.id');

        $codigo = $this->conQr($id);
        $this->assertNotNull($codigo);

        // El backfill no tiene nada que hacer aquí, y no lo hace.
        $this->artisan('meetings:backfill-qr')
            ->expectsOutputToContain('0 reunión(es)')
            ->assertExitCode(0);

        $this->assertSame($codigo, $this->conQr($id));
    }
}

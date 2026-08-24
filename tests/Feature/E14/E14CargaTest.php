<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subir actas desde el panel y encolarlas (Spec 0071).
 *
 * Lo que se prueba es el gesto real: alguien arrastra una tanda de PDFs, alguno
 * repetido, y luego da la orden de procesar. Nada de eso puede duplicar una mesa
 * ni reabrir una que ya se leyó.
 */
class E14CargaTest extends TestCase
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

    /**
     * Un acta de las de antes de la 0072: subida y ahí olvidada, en `cargada`.
     *
     * Es el caso que `procesar` sigue existiendo para rescatar.
     */
    private function legado(Tenant $tenant, string $tipo): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => ucfirst($tipo),
        ]);

        return E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => $tipo,
            'archivo_nombre' => "vieja-{$tipo}.pdf",
            'archivo_hash' => hash('sha256', "{$tenant->id}-{$tipo}-legado"),
            'archivo_path' => "e14/{$tenant->id}/legado-{$tipo}.pdf",
            'estado' => E14Acta::ESTADO_CARGADA,
        ]);
    }

    private function pdf(string $nombre = 'mesa001.pdf', string $contenido = 'ACTA-001'): UploadedFile
    {
        // Un PDF de verdad, mínimo pero con cabecera: la validación mira el
        // mimetype real, no la extensión.
        return UploadedFile::fake()->createWithContent(
            $nombre,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%{$contenido}\n%%EOF"
        );
    }

    public function test_un_acta_subida_queda_encolada_con_su_pdf_guardado(): void
    {
        $this->operador();

        $respuesta = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ]);

        // Cargar es encolar (Spec 0072): no hay un segundo paso que olvidar.
        $respuesta->assertStatus(201)
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.tipo', 'alcaldia')
            ->assertJsonPath('data.archivo_nombre', 'mesa001.pdf')
            ->assertJsonPath('data.tiene_archivo', true)
            ->assertJsonPath('duplicada', false);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        $this->assertNotNull($acta->archivo_hash);
        $this->assertNotNull($acta->upload_batch_id);
        Storage::disk(config('e14.disk'))->assertExists($acta->archivo_path);
    }

    public function test_el_pdf_se_guarda_bajo_el_hash_de_su_contenido(): void
    {
        $tenant = $this->operador();

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(201);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        // Renombrar un PDF no lo convierte en un acta nueva: el nombre en disco
        // es el contenido, no lo que traía el archivo.
        $this->assertSame("e14/{$tenant->id}/{$acta->archivo_hash}.pdf", $acta->archivo_path);
    }

    public function test_el_mismo_pdf_dos_veces_no_crea_dos_actas(): void
    {
        $this->operador();

        $primera = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf('mesa001.pdf'),
        ])->assertStatus(201);

        // Mismo contenido, otro nombre: es la misma acta.
        $segunda = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf('copia-de-mesa001.pdf'),
        ]);

        $segunda->assertStatus(200)
            ->assertJsonPath('duplicada', true)
            ->assertJsonPath('data.id', $primera->json('data.id'));

        $this->assertSame(1, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_volver_a_subir_un_acta_ya_leida_no_la_devuelve_a_la_cola(): void
    {
        $this->operador();

        $id = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->json('data.id');

        E14Acta::withoutGlobalScope(TenantScope::class)->whereKey($id)
            ->update(['estado' => 'procesada']);

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(200)->assertJsonPath('data.estado', 'procesada');
    }

    public function test_dos_campanas_pueden_subir_el_mismo_pdf(): void
    {
        $this->operador();
        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(201);

        // La deduplicación es por tenant: que otra campaña tenga el mismo
        // escaneo no puede impedir que esta lo cargue.
        $this->operador();
        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(201)->assertJsonPath('duplicada', false);

        $this->assertSame(2, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_el_lote_agrupa_los_pdfs_de_una_misma_tanda(): void
    {
        $this->operador();

        $lote = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf('a.pdf', 'A'),
        ])->json('upload_batch_id');

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf('b.pdf', 'B'),
            'upload_batch_id' => $lote,
        ])->assertStatus(201)->assertJsonPath('data.upload_batch_id', $lote);

        $this->assertSame(
            2,
            E14Acta::withoutGlobalScope(TenantScope::class)->where('upload_batch_id', $lote)->count()
        );
    }

    public function test_lo_que_no_es_un_pdf_no_entra(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('archivo');

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_no_hay_que_decir_de_que_eleccion_es_porque_la_pone_la_campana(): void
    {
        // Hasta la 0093 el tipo lo elegía quien subía, y equivocarlo mandaba el
        // acta al parser que no le tocaba. Ya no se pregunta: la campaña sirve a
        // una sola elección (0093 · RF-B4).
        $this->operador();

        $this->postJson('/api/v1/e14/actas/upload', ['archivo' => $this->pdf()])
            ->assertStatus(201)
            ->assertJsonPath('data.tipo', 'alcaldia');
    }

    public function test_las_actas_de_corporacion_tambien_se_cargan(): void
    {
        // Concejo, senado y asamblea todavía no tienen parser (0067), pero eso
        // es cosa del worker: cargarlas y encolarlas ya funciona. Cada una en su
        // campaña, que es de lo que va la 0093.
        $cargos = [
            'Concejo' => 'concejo',
            'Congresista' => 'senado',
            'Diputado' => 'asamblea_departamental',
        ];

        foreach ($cargos as $cargo => $tipo) {
            $this->operador(tenant: Tenant::factory()->create(['tipo_cargo' => $cargo]));

            $this->postJson('/api/v1/e14/actas/upload', [
                'archivo' => $this->pdf("{$tipo}.pdf", $tipo),
            ])->assertStatus(201)->assertJsonPath('data.tipo', $tipo);
        }

        $this->assertSame(3, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
    }

    // ------------------------------------------------------------- encolar

    public function test_subir_ya_no_deja_nada_pendiente_de_encolar(): void
    {
        $this->operador();

        $lote = null;
        foreach (['a', 'b', 'c'] as $letra) {
            $lote = $this->postJson('/api/v1/e14/actas/upload', [
                'tipo' => 'alcaldia',
                'archivo' => $this->pdf("{$letra}.pdf", $letra),
                'upload_batch_id' => $lote,
            ])->json('upload_batch_id');
        }

        $this->assertSame(
            3,
            E14Acta::withoutGlobalScope(TenantScope::class)->where('estado', 'pendiente')->count(),
            'Las tres entran a la cola por el hecho de subirlas.'
        );

        // Y por tanto no queda nada que «procesar»: el paso que se podía
        // olvidar ya no existe (Spec 0072).
        $this->postJson('/api/v1/e14/actas/procesar', ['batch_id' => $lote])
            ->assertStatus(200)
            ->assertJsonPath('data.encoladas', 0);
    }

    public function test_el_worker_puede_reclamar_un_acta_recien_subida(): void
    {
        $this->operador();

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(201);

        // Sin ningún paso intermedio: subir y que el lector la tome.
        $this->postJson('/api/v1/e14/actas/siguiente')
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesando');
    }

    public function test_procesar_rescata_las_actas_que_se_quedaron_en_cargada(): void
    {
        $tenant = $this->operador();
        $this->legado($tenant, 'alcaldia');

        // Las que entraron antes de la 0072 siguen en `cargada`; el endpoint
        // existe justo para eso.
        $this->postJson('/api/v1/e14/actas/procesar')
            ->assertStatus(200)
            ->assertJsonPath('data.encoladas', 1);

        $this->assertSame(
            'pendiente',
            E14Acta::withoutGlobalScope(TenantScope::class)->first()->estado
        );
    }

    public function test_procesar_dos_veces_no_reencola_lo_que_ya_esta_en_marcha(): void
    {
        $tenant = $this->operador();
        $this->legado($tenant, 'alcaldia');

        $this->postJson('/api/v1/e14/actas/procesar')->assertJsonPath('data.encoladas', 1);
        $this->postJson('/api/v1/e14/actas/procesar')->assertJsonPath('data.encoladas', 0);
    }

    public function test_se_puede_encolar_solo_un_tipo(): void
    {
        $tenant = $this->operador();
        $this->legado($tenant, 'alcaldia');
        $this->legado($tenant, 'concejo');

        $this->postJson('/api/v1/e14/actas/procesar', ['tipo' => 'alcaldia'])
            ->assertJsonPath('data.encoladas', 1);

        $this->assertSame(
            'cargada',
            E14Acta::withoutGlobalScope(TenantScope::class)->where('tipo', 'concejo')->first()->estado
        );
    }

    public function test_encolar_no_alcanza_a_otra_campana(): void
    {
        $ajena = $this->operador();
        $this->legado($ajena, 'alcaldia');

        $this->operador();
        $this->postJson('/api/v1/e14/actas/procesar')->assertJsonPath('data.encoladas', 0);

        $this->assertSame(
            'cargada',
            E14Acta::withoutGlobalScope(TenantScope::class)->where('tenant_id', $ajena->id)->first()->estado
        );
    }

    // ------------------------------------------------------------- resumen

    public function test_el_resumen_cuenta_lo_que_falta(): void
    {
        $this->operador();

        foreach (['a', 'b'] as $letra) {
            $this->postJson('/api/v1/e14/actas/upload', [
                'tipo' => 'alcaldia',
                'archivo' => $this->pdf("{$letra}.pdf", $letra),
            ]);
        }

        $respuesta = $this->getJson('/api/v1/e14/resumen')->assertStatus(200);

        $respuesta->assertJsonPath('data.por_estado.pendiente', 2)
            ->assertJsonPath('data.por_estado.cargada', 0)
            ->assertJsonPath('data.por_estado.procesada', 0)
            ->assertJsonPath('data.por_tipo.alcaldia', 2)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.en_cola', 2);
    }

    // ------------------------------------------------------------ permisos

    public function test_sin_permiso_de_escritura_no_se_carga_ni_se_encola(): void
    {
        $this->operador([Permissions::VIEW_E14]);

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(403);

        $this->postJson('/api/v1/e14/actas/procesar')->assertStatus(403);

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_sin_permiso_de_lectura_no_se_ve_el_resumen(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson('/api/v1/e14/resumen')->assertStatus(403);
    }

    public function test_sin_credencial_no_se_carga_nada(): void
    {
        Tenant::factory()->create();

        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(401);

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }
}

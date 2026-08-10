<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Las dos salidas de la revisión: relerla o quitarla (Spec 0077 · Parte A).
 *
 * Cuando el lector no consigue transcribir un acta, «Guardar corrección» no
 * sirve: no hay una casilla que arreglar, está todo vacío. Quien revisa se
 * quedaba sin salida — ni podía pedir otra lectura ni quitar el acta para subir
 * un mejor escaneo. Estas dos acciones cierran ese flujo:
 *
 * - **Reprocesar** devuelve el acta a la cola y **descarta la lectura anterior**,
 *   conservando el archivo. Se rechaza si el acta está en vuelo: no se le quita
 *   el trabajo a un worker que ya la tiene.
 * - **Eliminar** borra la fila, sus resultados y el archivo. Al desaparecer la
 *   fila se libera el `archivo_hash`, que es lo que permite **volver a cargar el
 *   mismo PDF** sin que el dedup de la 0072 lo rechace.
 */
class E14ReprocesarEliminarTest extends TestCase
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

    private function evento(Tenant $tenant): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
        ]);
    }

    /**
     * Un acta ya leída, con su archivo en el disco y sus cifras puestas.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function leida(Tenant $tenant, array $cambios = [], string $semilla = 'a'): E14Acta
    {
        $hash = hash('sha256', "{$tenant->id}-{$semilla}");
        $ruta = "e14/{$tenant->id}/{$hash}.pdf";

        Storage::disk(config('e14.disk'))->put($ruta, '%PDF-1.4');

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->create(array_replace([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $this->evento($tenant)->id,
            'tipo' => 'alcaldia',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '00'.$semilla,
            'archivo_nombre' => "acta-{$semilla}.pdf",
            'archivo_hash' => $hash,
            'archivo_path' => $ruta,
            'estado' => E14Acta::ESTADO_REVISION_MANUAL,
            'suma_calculada' => 111,
            'suma_declarada' => 100,
            'votos_urna' => 100,
            'votantes_e11' => 120,
            'dif_nivelacion' => 20,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'confianza' => 42.5,
            'observacion' => 'la suma de las casillas no coincide',
            'fuente' => E14Acta::FUENTE_MANUAL,
            'intentos' => 2,
            'processed_at' => now(),
        ], $cambios));

        E14Resultado::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'e14_acta_id' => $acta->id,
            'numero' => 1,
            'nombre' => 'JORGE BOLIVAR TORRES',
            'votos' => 99,
        ]);

        return $acta;
    }

    private function pdf(string $nombre = 'mesa001.pdf', string $contenido = 'ACTA-001'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nombre,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%{$contenido}\n%%EOF"
        );
    }

    // ------------------------------------------------------------ reprocesar

    public function test_reprocesar_reencola_el_acta_y_descarta_la_lectura_anterior(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.suma_declarada', 0)
            ->assertJsonPath('data.observacion', null);

        $acta->refresh();

        $this->assertSame(E14Acta::ESTADO_PENDIENTE, $acta->estado);
        // Los intentos vuelven a cero: si no, un acta que ya falló dos veces
        // agotaría el tope en la primera relectura y volvería a revisión sola.
        $this->assertSame(0, $acta->intentos);
        $this->assertSame(E14Acta::FUENTE_VISION, $acta->fuente);

        foreach (['suma_calculada', 'suma_declarada', 'votos_urna', 'votantes_e11', 'dif_nivelacion', 'votos_blanco', 'votos_nulos', 'votos_no_marcados'] as $cifra) {
            $this->assertSame(0, $acta->{$cifra}, "«{$cifra}» tenía que quedar en cero.");
        }

        foreach (['confianza', 'observacion', 'claimed_at', 'processed_at'] as $campo) {
            $this->assertNull($acta->{$campo}, "«{$campo}» tenía que quedar vacío.");
        }

        // La lectura anterior no puede sobrevivir a la relectura: sumaría votos
        // de candidatos que quizá no estén en la siguiente.
        $this->assertSame(0, E14Resultado::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_reprocesar_conserva_el_archivo_y_la_identidad_del_acta(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);
        $original = $acta->only(['archivo_path', 'archivo_hash', 'archivo_nombre', 'tipo', 'electoral_event_id', 'departamento', 'municipio', 'lugar', 'zona', 'puesto', 'mesa']);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(200);

        $acta->refresh();

        // Lo que se relee es el mismo papel: si el archivo se fuera, no habría
        // nada que volver a leer.
        foreach ($original as $campo => $valor) {
            $this->assertSame($valor, $acta->{$campo}, "«{$campo}» no debía cambiar.");
        }

        Storage::disk(config('e14.disk'))->assertExists($acta->archivo_path);
    }

    public function test_el_worker_vuelve_a_recibir_el_acta_reprocesada(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        // Antes de reprocesar no está en la cola: ya se había leído.
        $this->postJson('/api/v1/e14/actas/siguiente')->assertStatus(204);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(200);

        $this->postJson('/api/v1/e14/actas/siguiente')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $acta->id)
            ->assertJsonPath('data.estado', 'procesando');
    }

    public function test_se_puede_reprocesar_un_acta_ya_procesada_y_sale_del_consolidado(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant, [
            'estado' => E14Acta::ESTADO_PROCESADA,
            'suma_calculada' => 111,
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'observacion' => null,
        ]);

        $this->getJson('/api/v1/e14/consolidado')
            ->assertJsonPath('meta.actas.procesada', 1);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'pendiente');

        // Reencolar saca el acta del consolidado hasta que se relea. Es el
        // efecto que la confirmación del panel tiene que advertir.
        $this->getJson('/api/v1/e14/consolidado')
            ->assertJsonPath('meta.actas.procesada', 0);
    }

    public function test_se_puede_reprocesar_un_acta_cargada_o_inconsistente(): void
    {
        $tenant = $this->operador();

        foreach ([E14Acta::ESTADO_CARGADA, E14Acta::ESTADO_INCONSISTENTE] as $i => $estado) {
            $acta = $this->leida($tenant, ['estado' => $estado], semilla: (string) ($i + 3));

            $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")
                ->assertStatus(200)
                ->assertJsonPath('data.estado', 'pendiente');
        }
    }

    public function test_no_se_le_quita_el_trabajo_a_un_worker_en_curso(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant, [
            'estado' => E14Acta::ESTADO_PROCESANDO,
            'claimed_at' => now(),
            'intentos' => 1,
        ]);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")
            ->assertStatus(409)
            ->assertJsonPath('errors.estado.0', fn ($mensaje) => str_contains($mensaje, 'worker'));

        $acta->refresh();

        // Nada cambió: ni el estado, ni el reclamo, ni la lectura.
        $this->assertSame(E14Acta::ESTADO_PROCESANDO, $acta->estado);
        $this->assertSame(1, $acta->intentos);
        $this->assertSame(1, E14Resultado::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_reprocesar_dos_veces_seguidas_rechaza_la_segunda(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(200);

        // Ya está en la cola: pedirlo otra vez no la encola dos veces.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(409);

        $this->assertSame(E14Acta::ESTADO_PENDIENTE, $acta->fresh()->estado);
    }

    /**
     * Enciende `owen-it`, que en la suite está apagado.
     *
     * El paquete se desactiva solo cuando la app corre en consola
     * (`audit.console = false`), que es el caso de todas las pruebas. Encenderlo
     * no basta: `Auditable::bootAuditable()` registra su observador **al arrancar
     * el modelo** y solo si la auditoría estaba activa en ese momento, así que
     * hay que volver a arrancarlo. De ahí el `clearBootedModels()`.
     *
     * Va **antes** de tocar el acta, y por eso mismo: si el modelo ya arrancó,
     * el observador ya no se engancha.
     */
    private function conAuditoria(): void
    {
        config()->set('audit.console', true);

        Model::clearBootedModels();
    }

    public function test_reprocesar_queda_auditado(): void
    {
        $this->conAuditoria();

        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(200);

        $this->assertTrue(
            Audit::where('auditable_type', E14Acta::class)
                ->where('auditable_id', $acta->id)
                ->where('event', 'updated')
                ->exists(),
            'Reencolar un acta cambia qué votos están en el consolidado: tiene que dejar rastro.'
        );
    }

    public function test_reprocesar_exige_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $acta = $this->leida($tenant);

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(403);

        $this->assertSame(E14Acta::ESTADO_REVISION_MANUAL, $acta->fresh()->estado);
    }

    public function test_no_se_puede_reprocesar_el_acta_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $acta = $this->leida($ajeno);

        $this->operador();

        $this->postJson("/api/v1/e14/actas/{$acta->id}/reprocesar")->assertStatus(404);

        $this->assertSame(E14Acta::ESTADO_REVISION_MANUAL, $acta->fresh()->estado);
    }

    // -------------------------------------------------------------- eliminar

    public function test_eliminar_borra_el_acta_sus_resultados_y_su_archivo(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);
        $ruta = $acta->archivo_path;

        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, 'eliminada'));

        $this->assertDatabaseMissing('e14_actas', ['id' => $acta->id]);
        $this->assertSame(0, E14Resultado::withoutGlobalScope(TenantScope::class)->count());
        Storage::disk(config('e14.disk'))->assertMissing($ruta);

        // Y deja de existir para todo el mundo, no solo para el listado.
        $this->getJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(404);
    }

    public function test_eliminar_libera_el_hash_para_volver_a_cargar_el_mismo_pdf(): void
    {
        $this->operador();

        $primera = $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])->assertStatus(201)->json('data.id');

        // Mientras el acta existe, el dedup por contenido devuelve la misma.
        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])
            ->assertStatus(200)
            ->assertJsonPath('duplicada', true)
            ->assertJsonPath('data.id', $primera);

        $this->deleteJson("/api/v1/e14/actas/{$primera}")->assertStatus(200);

        // Al desaparecer la fila se libera el `archivo_hash`: el mismo escaneo
        // vuelve a entrar como acta nueva, que es todo el propósito de eliminar.
        $this->postJson('/api/v1/e14/actas/upload', [
            'tipo' => 'alcaldia',
            'archivo' => $this->pdf(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('duplicada', false)
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.id', fn ($id) => $id !== $primera);
    }

    public function test_eliminar_un_acta_cuyo_archivo_ya_no_esta_igual_procede(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        Storage::disk(config('e14.disk'))->delete($acta->archivo_path);

        // El borrado del archivo es best-effort: un objeto que ya no está no
        // puede dejar la fila colgada para siempre.
        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(200);

        $this->assertDatabaseMissing('e14_actas', ['id' => $acta->id]);
    }

    public function test_se_puede_eliminar_un_acta_que_un_worker_tiene_en_la_mano(): void
    {
        $tenant = $this->operador();
        $acta = $this->leida($tenant, [
            'estado' => E14Acta::ESTADO_PROCESANDO,
            'claimed_at' => now(),
        ]);

        // El borrado es la última palabra: quitar un acta no puede depender de
        // que un worker termine.
        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(200);

        // Y el resultado que llegue después no la resucita.
        $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", [
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 10,
            'votos_urna' => 10,
            'votantes_e11' => 10,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 10]],
        ])->assertStatus(404);

        $this->assertDatabaseMissing('e14_actas', ['id' => $acta->id]);
    }

    public function test_eliminar_queda_auditado(): void
    {
        $this->conAuditoria();

        $tenant = $this->operador();
        $acta = $this->leida($tenant);

        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(200);

        $this->assertTrue(
            Audit::where('auditable_type', E14Acta::class)
                ->where('auditable_id', $acta->id)
                ->where('event', 'deleted')
                ->exists(),
            'Borrar el escrutinio de una mesa es justo lo que después alguien va a pedir cuentas.'
        );
    }

    public function test_eliminar_exige_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $acta = $this->leida($tenant);

        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(403);

        $this->assertDatabaseHas('e14_actas', ['id' => $acta->id]);
    }

    public function test_no_se_puede_eliminar_el_acta_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $acta = $this->leida($ajeno);
        $ruta = $acta->archivo_path;

        $this->operador();

        $this->deleteJson("/api/v1/e14/actas/{$acta->id}")->assertStatus(404);

        $this->assertDatabaseHas('e14_actas', ['id' => $acta->id]);
        Storage::disk(config('e14.disk'))->assertExists($ruta);
    }
}

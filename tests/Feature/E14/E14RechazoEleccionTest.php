<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ActaRechazada;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El acta que no es de esta elección se rechaza (Spec 0093 · capa 2).
 *
 * La capa 1 impide **elegir** otra elección; esta impide **colar** un acta de
 * otra. El lector lee la elección impresa en el encabezado y la manda al
 * publicar; si no es la de la campaña, el acta no se procesa: se borra en duro
 * —fila y archivo, como la 0077— y queda un renglón que explica el hueco.
 *
 * La regla que gobierna todo esto es la de la duda: un encabezado que no se pudo
 * leer **no borra nada**. Un OCR flojo no puede tener permiso de borrado, y el
 * precio de equivocarse por exceso es un acta perdida sin vuelta atrás.
 */
class E14RechazoEleccionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('e14.disk'));
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function operador(?Tenant $tenant = null, array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14]): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * Un acta en la cola, con su PDF en el disco: lo que el worker reclama.
     */
    private function pendiente(Tenant $tenant, string $tipo = 'alcaldia', string $semilla = 'a'): E14Acta
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => E14Acta::nombreDe($tipo),
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
     * Una lectura uninominal que cuadra: 10 + 5 + 3 + 1 + 1 = 20.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function lectura(array $cambios = []): array
    {
        return array_replace([
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 20,
            'votos_urna' => 20,
            'votantes_e11' => 20,
            'votos_blanco' => 3,
            'votos_nulos' => 1,
            'votos_no_marcados' => 1,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR', 'votos' => 10],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 5],
            ],
        ], $cambios);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function publicar(E14Acta $acta, array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/e14/actas/{$acta->id}/resultado", $this->lectura($cambios));
    }

    private function pdf(string $contenido = 'ACTA-001'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'mesa001.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%{$contenido}\n%%EOF"
        );
    }

    // ------------------------------------------------------------- rechazo

    public function test_un_acta_de_otra_eleccion_se_borra_con_su_archivo_y_deja_constancia(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);
        $ruta = $acta->archivo_path;

        $this->publicar($acta, ['eleccion_detectada' => 'gobernacion'])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'rechazada')
            ->assertJsonPath('data', null)
            ->assertJsonPath('rechazo.eleccion_detectada', 'gobernacion')
            ->assertJsonPath('rechazo.eleccion_esperada', 'alcaldia')
            // El motivo llega redactado y en español: es lo que el panel enseña
            // sin traducir nada (Art. IX).
            ->assertJsonPath('rechazo.eleccion_detectada_nombre', 'Gobernación')
            ->assertJsonPath('rechazo.eleccion_esperada_nombre', 'Alcaldía')
            ->assertJsonPath('message', 'El acta es de Gobernación y esta campaña escruta Alcaldía; se eliminó junto con su archivo.');

        // El acta ya no existe: borrado duro, como el de la 0077. Es lo que
        // libera el `archivo_hash` para que el mismo PDF pueda volver a entrar
        // si la detección se equivocó.
        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
        Storage::disk(config('e14.disk'))->assertMissing($ruta);

        $rechazo = E14ActaRechazada::withoutGlobalScope(TenantScope::class)->sole();

        $this->assertSame($tenant->id, $rechazo->tenant_id);
        $this->assertSame('acta-a.pdf', $rechazo->archivo_nombre);
        $this->assertSame($acta->archivo_hash, $rechazo->archivo_hash);
        $this->assertSame('gobernacion', $rechazo->eleccion_detectada);
        $this->assertSame('alcaldia', $rechazo->eleccion_esperada);
    }

    public function test_el_registro_de_rechazo_no_guarda_nada_del_contenido_del_acta(): void
    {
        $tenant = $this->operador();

        $this->publicar($this->pendiente($tenant), ['eleccion_detectada' => 'senado'])
            ->assertStatus(200);

        // El acta rechazada nunca se leyó: aquí no hay mesa, ni cifras, ni
        // candidatos. Solo metadatos del archivo y las dos elecciones (Art. VII).
        $columnas = array_keys(E14ActaRechazada::withoutGlobalScope(TenantScope::class)->sole()->getAttributes());

        $this->assertEqualsCanonicalizing([
            'id', 'tenant_id', 'electoral_event_id',
            'archivo_nombre', 'archivo_hash',
            'eleccion_detectada', 'eleccion_esperada', 'motivo', 'created_at',
        ], $columnas);
    }

    public function test_una_campana_de_corporacion_tambien_rechaza_lo_ajeno(): void
    {
        // El rechazo compara por tipo de elección, no por uninominal contra
        // corporación: senado y concejo son las dos corporación y siguen siendo
        // dos elecciones distintas (spec §9).
        $tenant = $this->operador(Tenant::factory()->corporacion()->create());
        $acta = $this->pendiente($tenant, 'concejo');

        // Un acta de corporación trae agrupaciones, no candidatos (Spec 0067):
        // 10 + 5 de listas + 5 de controles = 20 de urna.
        $this->publicar($acta, [
            'eleccion_detectada' => 'senado',
            'suma_declarada' => null,
            'resultados' => [],
            'listas' => [
                [
                    'lista_numero' => 11,
                    'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                    'votos_solo_lista' => 2,
                    'total_agrupacion' => 10,
                    'preferentes' => [['numero' => 1, 'votos' => 8]],
                ],
                [
                    'lista_numero' => 1,
                    'lista_nombre' => 'PARTIDO LIBERAL COLOMBIANO',
                    'votos_solo_lista' => 1,
                    'total_agrupacion' => 5,
                    'preferentes' => [['numero' => 1, 'votos' => 4]],
                ],
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'rechazada')
            ->assertJsonPath('rechazo.eleccion_esperada', 'concejo');

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    // ---------------------------------------------------- la duda no borra

    public function test_si_el_lector_no_supo_leer_el_encabezado_no_se_borra_nada(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->publicar($acta, ['eleccion_detectada' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(1, E14Acta::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, E14ActaRechazada::withoutGlobalScope(TenantScope::class)->count());
        Storage::disk(config('e14.disk'))->assertExists($acta->archivo_path);
    }

    public function test_un_lector_viejo_que_ni_manda_el_campo_sigue_funcionando(): void
    {
        // Compatibilidad: la parte A entra antes que el lector (plan §5), así
        // que durante un tiempo nadie manda `eleccion_detectada`.
        $tenant = $this->operador();

        $this->publicar($this->pendiente($tenant))
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(1, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_una_eleccion_que_el_e14_no_conoce_vale_como_duda(): void
    {
        $tenant = $this->operador();

        // «CÁMARA» no es un tipo del E-14. Tratarlo como ajeno borraría el acta
        // por una palabra rara; vale lo mismo que no haber leído nada (§9).
        $this->publicar($this->pendiente($tenant), ['eleccion_detectada' => 'camara'])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada');

        $this->assertSame(0, E14ActaRechazada::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_si_coincide_con_la_eleccion_de_la_campana_sigue_el_flujo_normal(): void
    {
        $tenant = $this->operador();

        $this->publicar($this->pendiente($tenant), ['eleccion_detectada' => 'alcaldia'])
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'procesada')
            ->assertJsonPath('data.mesa', '001');
    }

    // -------------------------------------------------------- idempotencia

    public function test_reenviar_el_resultado_rechazado_no_recrea_el_acta(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->publicar($acta, ['eleccion_detectada' => 'gobernacion'])->assertStatus(200);

        // El acta ya no existe, así que el reintento recibe el 404 que el worker
        // tolera desde la 0077. Lo que no puede es resucitarla.
        $this->publicar($acta, ['eleccion_detectada' => 'gobernacion'])->assertStatus(404);

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, E14ActaRechazada::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_volver_a_cargar_y_volver_a_rechazar_el_mismo_pdf_no_duplica_la_constancia(): void
    {
        $tenant = $this->operador();
        $acta = $this->pendiente($tenant);

        $this->publicar($acta, ['eleccion_detectada' => 'gobernacion'])->assertStatus(200);

        // El hash quedó libre con el borrado, así que el mismo PDF entra otra
        // vez —es justo para lo que sirve el borrado duro—. Al volver a
        // rechazarse, el renglón se actualiza en vez de acumularse (Art. VIII).
        $otra = $this->pendiente($tenant);

        $this->publicar($otra, ['eleccion_detectada' => 'gobernacion'])->assertStatus(200);

        $this->assertSame(1, E14ActaRechazada::withoutGlobalScope(TenantScope::class)->count());
    }

    // ------------------------------------------------------- GET /rechazos

    public function test_el_panel_ve_sus_rechazos_con_el_motivo(): void
    {
        $tenant = $this->operador();

        $this->publicar($this->pendiente($tenant), ['eleccion_detectada' => 'gobernacion'])
            ->assertStatus(200);

        $this->getJson('/api/v1/e14/rechazos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.archivo_nombre', 'acta-a.pdf')
            ->assertJsonPath('data.0.eleccion_detectada_nombre', 'Gobernación')
            ->assertJsonPath('data.0.eleccion_esperada_nombre', 'Alcaldía')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_los_rechazos_de_una_campana_no_se_ven_desde_otra(): void
    {
        $ajena = $this->operador(Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']));
        $this->publicar($this->pendiente($ajena), ['eleccion_detectada' => 'gobernacion'])
            ->assertStatus(200);

        $this->operador(Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']));

        $this->getJson('/api/v1/e14/rechazos')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_ver_los_rechazos_exige_view_e14(): void
    {
        $this->operador(permisos: [Permissions::MANAGE_E14]);

        $this->getJson('/api/v1/e14/rechazos')->assertStatus(403);
    }

    public function test_sin_credencial_no_se_ven_los_rechazos(): void
    {
        Tenant::factory()->create();

        $this->getJson('/api/v1/e14/rechazos')->assertStatus(401);
    }

    // ------------------------------------------- el hueco tiene explicación

    public function test_de_un_lote_mixto_queda_lo_propio_y_la_constancia_de_lo_ajeno(): void
    {
        $tenant = $this->operador();

        // El gesto real: alguien sube dos PDFs y uno era de otra elección.
        $primera = $this->postJson('/api/v1/e14/actas/upload', ['archivo' => $this->pdf('A')])
            ->assertStatus(201)->json('data.id');
        $segunda = $this->postJson('/api/v1/e14/actas/upload', ['archivo' => $this->pdf('B')])
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/e14/actas/{$primera}/resultado", $this->lectura([
            'eleccion_detectada' => 'alcaldia',
        ]))->assertStatus(200)->assertJsonPath('data.estado', 'procesada');

        $this->postJson("/api/v1/e14/actas/{$segunda}/resultado", $this->lectura([
            'mesa' => '002',
            'eleccion_detectada' => 'gobernacion',
        ]))->assertStatus(200)->assertJsonPath('estado', 'rechazada');

        $this->getJson('/api/v1/e14/actas')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mesa', '001');

        $this->getJson('/api/v1/e14/rechazos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}

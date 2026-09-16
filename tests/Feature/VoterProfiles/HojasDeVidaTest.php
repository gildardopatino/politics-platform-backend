<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterProfile;
use App\Models\VoterResume;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hojas de vida del elector (Spec 0096).
 *
 * `Storage::fake()` en todas: ninguna prueba escribe en el disco real.
 */
class HojasDeVidaTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private Voter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::factory()->create();
        [$this->user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenant
        );

        $this->voter = Voter::factory()->forTenant($this->tenant)->create();
        $this->conAutorizacion($this->voter);

        $this->actingAsTenantUser($this->user);
    }

    // ------------------------------------------------------------------
    // Subida
    // ------------------------------------------------------------------

    public function test_sube_una_hoja_de_vida_y_la_deja_en_la_carpeta_de_su_tenant(): void
    {
        $respuesta = $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf('hoja de vida ana.pdf', 2048),
        ])->assertStatus(201);

        $this->assertSame('hoja de vida ana.pdf', $respuesta->json('data.nombre_original'));
        $this->assertSame('application/pdf', $respuesta->json('data.mime'));

        $hoja = VoterResume::firstOrFail();

        $this->assertSame($this->tenant->id, $hoja->tenant_id);
        $this->assertSame($this->user->id, $hoja->subido_por);
        $this->assertStringStartsWith(
            "hojas-vida/{$this->tenant->id}/{$this->voter->id}/",
            $hoja->archivo_key
        );

        Storage::disk('local')->assertExists($hoja->archivo_key);
    }

    public function test_el_nombre_en_disco_lo_pone_el_servidor_no_quien_sube(): void
    {
        $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf('../../../etc/passwd.pdf'),
        ])->assertStatus(201);

        $hoja = VoterResume::firstOrFail();

        $this->assertStringNotContainsString('..', $hoja->archivo_key);
        $this->assertStringStartsWith('hojas-vida/', $hoja->archivo_key);
        $this->assertStringNotContainsString('/', $hoja->nombre_original);
    }

    public function test_guarda_varias_versiones_y_las_lista_de_la_mas_reciente_a_la_mas_antigua(): void
    {
        foreach (['primera.pdf', 'segunda.pdf'] as $nombre) {
            $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
                'archivo' => $this->pdf($nombre),
            ])->assertStatus(201);
        }

        $datos = $this->getJson("/api/v1/voters/{$this->voter->id}/hojas-vida")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $datos);
        $this->assertSame('segunda.pdf', $datos[0]['nombre_original']);
        $this->assertSame('primera.pdf', $datos[1]['nombre_original']);
    }

    public function test_el_listado_nunca_devuelve_la_ruta_en_disco(): void
    {
        $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf(),
        ])->assertStatus(201);

        $cuerpo = $this->getJson("/api/v1/voters/{$this->voter->id}/hojas-vida")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('archivo_key', $cuerpo);
        $this->assertStringNotContainsString('hojas-vida/'.$this->tenant->id, $cuerpo);
    }

    // ------------------------------------------------------------------
    // Ley 1581
    // ------------------------------------------------------------------

    public function test_sin_autorizacion_no_se_sube_y_no_queda_nada_en_disco(): void
    {
        VoterProfile::where('voter_id', $this->voter->id)
            ->update(['autoriza_tratamiento_datos' => false]);

        $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['autoriza_tratamiento_datos']);

        $this->assertDatabaseCount('voter_resumes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_un_elector_sin_perfil_recibe_422_diciendo_que_falta_el_perfil(): void
    {
        $sinPerfil = Voter::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/voters/{$sinPerfil->id}/hojas-vida", [
            'archivo' => $this->pdf(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['autoriza_tratamiento_datos']);

        $this->assertDatabaseCount('voter_resumes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ------------------------------------------------------------------
    // Tipo y tamaño
    // ------------------------------------------------------------------

    public function test_un_ejecutable_renombrado_a_pdf_se_rechaza(): void
    {
        $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => UploadedFile::fake()->create('hoja.pdf', 20, 'application/x-dosexec'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['archivo']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_un_archivo_de_mas_de_8_mb_se_rechaza(): void
    {
        $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf('grande.pdf', 20480),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['archivo']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_acepta_word_y_las_imagenes_del_catalogo(): void
    {
        $permitidos = [
            UploadedFile::fake()->create('hoja.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            UploadedFile::fake()->create('hoja.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('hoja.png', 50, 'image/png'),
        ];

        foreach ($permitidos as $archivo) {
            $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", ['archivo' => $archivo])
                ->assertStatus(201);
        }

        $this->assertDatabaseCount('voter_resumes', 3);
    }

    // ------------------------------------------------------------------
    // Descarga firmada
    // ------------------------------------------------------------------

    public function test_la_descarga_con_firma_valida_entrega_el_archivo(): void
    {
        $url = $this->postJson("/api/v1/voters/{$this->voter->id}/hojas-vida", [
            'archivo' => $this->pdf('hoja.pdf'),
        ])->assertStatus(201)->json('data.url_descarga');

        $this->flushHeaders()
            ->get($url)
            ->assertStatus(200)
            ->assertHeader('content-disposition', 'attachment; filename=hoja.pdf');
    }

    public function test_sin_firma_la_descarga_se_rechaza(): void
    {
        $hoja = $this->hojaConArchivo($this->voter);

        $this->flushHeaders()
            ->get("/api/v1/hojas-vida/{$hoja->id}/archivo")
            ->assertStatus(403);
    }

    public function test_una_firma_manipulada_no_sirve_para_pedir_otra_hoja(): void
    {
        $propia = $this->hojaConArchivo($this->voter);
        $otra = $this->hojaConArchivo($this->voter);

        $url = app(\App\Services\VoterResumeService::class)->urlFirmada($propia);
        $manipulada = str_replace("/hojas-vida/{$propia->id}/", "/hojas-vida/{$otra->id}/", $url);

        $this->flushHeaders()->get($manipulada)->assertStatus(403);
    }

    public function test_una_firma_vencida_se_rechaza(): void
    {
        $hoja = $this->hojaConArchivo($this->voter);

        $url = app(\App\Services\VoterResumeService::class)->urlFirmada($hoja);

        $this->travel((int) config('voter_resumes.url_ttl_minutes') + 1)->minutes();

        $this->flushHeaders()->get($url)->assertStatus(403);
    }

    public function test_la_hoja_de_otro_tenant_responde_como_inexistente_aunque_la_firma_sea_valida(): void
    {
        $otroTenant = Tenant::factory()->create();
        $ajeno = Voter::factory()->forTenant($otroTenant)->create();
        $hoja = $this->hojaConArchivo($ajeno);

        // Firma legítima de la hoja ajena pero con el tenant de esta campaña: es
        // el intento de reutilizar el enlace entre campañas.
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'hojas-vida.archivo',
            now()->addMinutes(5),
            ['resume' => $hoja->id, 'tenant' => $this->tenant->id],
        );

        $this->flushHeaders()->get($url)->assertStatus(404);
    }

    public function test_una_hoja_sin_archivo_en_disco_responde_404_y_no_revienta(): void
    {
        $hoja = VoterResume::factory()->paraVotante($this->voter)->create();

        $url = app(\App\Services\VoterResumeService::class)->urlFirmada($hoja);

        $this->flushHeaders()->get($url)->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Borrado
    // ------------------------------------------------------------------

    public function test_el_borrado_quita_la_fila_y_el_archivo(): void
    {
        $hoja = $this->hojaConArchivo($this->voter);

        $this->deleteJson("/api/v1/hojas-vida/{$hoja->id}")->assertStatus(200);

        $this->assertDatabaseMissing('voter_resumes', ['id' => $hoja->id]);
        Storage::disk('local')->assertMissing($hoja->archivo_key);
    }

    public function test_si_el_archivo_ya_no_esta_la_fila_se_borra_igual(): void
    {
        $hoja = VoterResume::factory()->paraVotante($this->voter)->create();

        $this->deleteJson("/api/v1/hojas-vida/{$hoja->id}")->assertStatus(200);

        $this->assertDatabaseMissing('voter_resumes', ['id' => $hoja->id]);
    }

    public function test_borrar_una_hoja_inexistente_da_404(): void
    {
        $this->deleteJson('/api/v1/hojas-vida/999999')->assertStatus(404);
    }

    public function test_borrar_al_elector_arrastra_sus_hojas_de_vida(): void
    {
        $hoja = $this->hojaConArchivo($this->voter);

        $this->voter->forceDelete();

        $this->assertDatabaseMissing('voter_resumes', ['id' => $hoja->id]);
    }

    private function conAutorizacion(Voter $voter): VoterProfile
    {
        return VoterProfile::factory()->paraVotante($voter)->create([
            'autoriza_tratamiento_datos' => true,
            'autorizado_at' => now(),
        ]);
    }

    private function hojaConArchivo(Voter $voter): VoterResume
    {
        $hoja = VoterResume::factory()->paraVotante($voter)->create();

        Storage::disk('local')->put($hoja->archivo_key, 'contenido de prueba');

        return $hoja;
    }

    private function pdf(string $nombre = 'hoja.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, $kb, 'application/pdf');
    }
}

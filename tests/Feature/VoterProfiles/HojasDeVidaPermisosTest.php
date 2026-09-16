<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Occupation;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VoterOccupation;
use App\Models\VoterProfile;
use App\Models\VoterResume;
use Database\Seeders\OccupationsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Permisos, aislamiento y coste del listado (Spec 0096, Art. III · VI · VII).
 */
class HojasDeVidaPermisosTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    public function test_sin_view_voter_profiles_el_listado_da_403(): void
    {
        $voter = $this->votanteConPerfil($this->tenantA);
        [$user] = $this->createTenantWithUser(['view_voters'], $this->tenantA);

        $this->actingAsTenantUser($user)
            ->getJson("/api/v1/voters/{$voter->id}/hojas-vida")
            ->assertStatus(403);
    }

    public function test_sin_manage_voter_profiles_no_se_sube_ni_se_borra(): void
    {
        $voter = $this->votanteConPerfil($this->tenantA);
        $hoja = VoterResume::factory()->paraVotante($voter)->create();

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);

        $this->actingAsTenantUser($user)
            ->postJson("/api/v1/voters/{$voter->id}/hojas-vida", [
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->deleteJson("/api/v1/hojas-vida/{$hoja->id}")->assertStatus(403);

        $this->assertDatabaseHas('voter_resumes', ['id' => $hoja->id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ------------------------------------------------------------------
    // Aislamiento
    // ------------------------------------------------------------------

    public function test_un_tenant_no_lista_las_hojas_de_un_elector_de_otro(): void
    {
        $ajeno = $this->votanteConPerfil($this->tenantB);
        VoterResume::factory()->paraVotante($ajeno)->create();

        [$user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenantA
        );

        // El binding del elector ya está acotado: uno ajeno no existe.
        $this->actingAsTenantUser($user)
            ->getJson("/api/v1/voters/{$ajeno->id}/hojas-vida")
            ->assertStatus(404);
    }

    public function test_un_tenant_no_borra_la_hoja_de_otro_y_no_se_le_confirma_que_existe(): void
    {
        $ajeno = $this->votanteConPerfil($this->tenantB);
        $hoja = VoterResume::factory()->paraVotante($ajeno)->create();

        [$user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenantA
        );

        // Mismo 404 que una hoja inexistente: la respuesta no distingue.
        $this->actingAsTenantUser($user)
            ->deleteJson("/api/v1/hojas-vida/{$hoja->id}")
            ->assertStatus(404);

        $this->deleteJson('/api/v1/hojas-vida/999999')->assertStatus(404);

        $this->assertDatabaseHas('voter_resumes', ['id' => $hoja->id]);
    }

    public function test_un_tenant_no_puede_adjuntar_al_elector_de_otro(): void
    {
        $ajeno = $this->votanteConPerfil($this->tenantB);

        [$user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenantA
        );

        $this->actingAsTenantUser($user)
            ->postJson("/api/v1/voters/{$ajeno->id}/hojas-vida", [
                'archivo' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('voter_resumes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ------------------------------------------------------------------
    // La bolsa de empleo
    // ------------------------------------------------------------------

    public function test_la_bolsa_dice_quien_tiene_hoja_de_vida(): void
    {
        $conHoja = $this->votanteConPerfil($this->tenantA);
        VoterResume::factory()->paraVotante($conHoja)->create();

        $sinHoja = $this->votanteConPerfil($this->tenantA);

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);

        $datos = $this->actingAsTenantUser($user)
            ->getJson('/api/v1/voters/perfiles')
            ->assertStatus(200)
            ->json('data');

        $porVotante = collect($datos)->keyBy(fn (array $fila) => $fila['voter_id']);

        $this->assertTrue($porVotante[$conHoja->id]['tiene_hoja_vida']);
        $this->assertSame(1, $porVotante[$conHoja->id]['hojas_vida_count']);

        $this->assertFalse($porVotante[$sinHoja->id]['tiene_hoja_vida']);
        $this->assertSame(0, $porVotante[$sinHoja->id]['hojas_vida_count']);
    }

    public function test_el_conteo_de_hojas_de_vida_no_agrega_una_consulta_por_fila(): void
    {
        $this->seed(OccupationsSeeder::class);

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);

        for ($i = 0; $i < 8; $i++) {
            $voter = $this->votanteConPerfil($this->tenantA);

            VoterOccupation::create([
                'tenant_id' => $this->tenantA->id,
                'voter_id' => $voter->id,
                'occupation_id' => Occupation::where('nombre', 'Vigilante')->value('id'),
                'relacion' => 'busca',
            ]);

            VoterResume::factory()->paraVotante($voter)->create();
        }

        $this->actingAsTenantUser($user);

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $respuesta = $this->getJson('/api/v1/voters/perfiles?per_page=50')->assertStatus(200);

        $this->assertSame(8, $respuesta->json('meta.total'));

        // `withCount` va dentro de la consulta de los perfiles: el coste no
        // cambia con el número de filas (Art. VI).
        $this->assertLessThanOrEqual(
            12,
            $consultas,
            "El buscador hizo {$consultas} consultas para 8 perfiles con hoja de vida: huele a N+1."
        );
    }

    private function votanteConPerfil(Tenant $tenant): Voter
    {
        $voter = Voter::factory()->forTenant($tenant)->create();

        VoterProfile::factory()->paraVotante($voter)->create([
            'autoriza_tratamiento_datos' => true,
            'autorizado_at' => now(),
        ]);

        return $voter;
    }
}

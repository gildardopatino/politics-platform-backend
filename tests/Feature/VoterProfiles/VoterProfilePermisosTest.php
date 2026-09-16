<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Occupation;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VoterProfile;
use Database\Seeders\OccupationsSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Permisos propios y aislamiento entre campañas (Spec 0094, Art. III y VII).
 */
class VoterProfilePermisosTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OccupationsSeeder::class);

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    public function test_sin_view_voter_profiles_la_lectura_da_403(): void
    {
        $votante = Voter::factory()->forTenant($this->tenantA)->create();
        [$user] = $this->createTenantWithUser(['view_voters'], $this->tenantA);

        $this->actingAsTenantUser($user);

        $this->getJson('/api/v1/voters/perfiles')->assertStatus(403);
        $this->getJson("/api/v1/voters/{$votante->id}/perfil")->assertStatus(403);
        $this->getJson('/api/v1/oficios')->assertStatus(403);
    }

    public function test_sin_manage_voter_profiles_la_escritura_da_403(): void
    {
        $votante = Voter::factory()->forTenant($this->tenantA)->create();
        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);

        $this->actingAsTenantUser($user);

        $this->putJson("/api/v1/voters/{$votante->id}/perfil", ['busca_empleo' => true])
            ->assertStatus(403);

        $this->assertDatabaseMissing('voter_profiles', ['voter_id' => $votante->id]);
    }

    public function test_un_tenant_no_ve_los_perfiles_de_otro(): void
    {
        $ajeno = Voter::factory()->forTenant($this->tenantB)->create();
        VoterProfile::factory()->paraVotante($ajeno)->create(['notas' => 'secreto del tenant B']);

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);
        $this->actingAsTenantUser($user);

        $this->getJson('/api/v1/voters/perfiles')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);

        // El binding del votante ya está acotado: un votante ajeno no existe.
        $this->getJson("/api/v1/voters/{$ajeno->id}/perfil")->assertStatus(404);
    }

    public function test_un_tenant_no_puede_escribir_el_perfil_de_un_votante_ajeno(): void
    {
        $ajeno = Voter::factory()->forTenant($this->tenantB)->create();

        [$user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenantA
        );
        $this->actingAsTenantUser($user);

        $this->putJson("/api/v1/voters/{$ajeno->id}/perfil", ['busca_empleo' => true])
            ->assertStatus(404);

        $this->assertDatabaseMissing('voter_profiles', ['voter_id' => $ajeno->id]);
    }

    public function test_el_catalogo_de_oficios_no_tiene_dueno(): void
    {
        // Es global por construcción, como `voting_places`: sin `tenant_id` no
        // hay forma de que una campaña vea un catálogo distinto del de otra.
        $this->assertFalse(
            Schema::hasColumn('occupations', 'tenant_id'),
            'El catálogo de oficios es global (Spec 0094 §10): no lleva tenant_id.'
        );
        $this->assertFalse(Schema::hasColumn('occupation_aliases', 'tenant_id'));
    }

    /**
     * @dataProvider campanias
     */
    public function test_las_dos_campanas_ven_el_mismo_catalogo(string $cual): void
    {
        // Una campaña por corrida y no las dos en la misma: el contenedor de
        // pruebas conserva `current_tenant_id` entre peticiones, así que cambiar
        // de usuario a media prueba mediría el artefacto y no el contrato.
        $tenant = $cual === 'A' ? $this->tenantA : $this->tenantB;

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $tenant);

        $oficios = $this->actingAsTenantUser($user)->getJson('/api/v1/oficios')
            ->assertStatus(200)->json('data');

        $this->assertNotEmpty($oficios);
        $this->assertSame(
            Occupation::activos()->orderBy('nombre')->pluck('id')->all(),
            array_column($oficios, 'id'),
            'El catálogo es global: cada campaña ve el catálogo completo.'
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function campanias(): array
    {
        return ['campaña A' => ['A'], 'campaña B' => ['B']];
    }

    public function test_el_catalogo_solo_expone_los_oficios_activos(): void
    {
        Occupation::create(['nombre' => 'Oficio retirado', 'activo' => false]);

        [$user] = $this->createTenantWithUser(['view_voter_profiles'], $this->tenantA);

        $nombres = array_column(
            $this->actingAsTenantUser($user)->getJson('/api/v1/oficios')->json('data'),
            'nombre'
        );

        $this->assertNotContains('Oficio retirado', $nombres);
    }
}

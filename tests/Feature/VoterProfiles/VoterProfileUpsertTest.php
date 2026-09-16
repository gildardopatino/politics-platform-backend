<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Occupation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use Database\Seeders\OccupationsSeeder;
use Tests\TestCase;

/**
 * `PUT /voters/{voter}/perfil` — captura del perfil laboral (Spec 0094).
 */
class VoterProfileUpsertTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private Voter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OccupationsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        [$this->user] = $this->createTenantWithUser(
            ['view_voter_profiles', 'manage_voter_profiles'],
            $this->tenant
        );
        $this->voter = Voter::factory()->forTenant($this->tenant)->create();

        $this->actingAsTenantUser($this->user);
    }

    public function test_el_primer_put_crea_el_perfil_con_sus_oficios(): void
    {
        $respuesta = $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'busca_empleo' => true,
            'disponibilidad' => 'inmediata',
            'anios_experiencia' => 5,
            'notas' => 'Dijo en la reunión que necesita algo cerca del barrio.',
            'oficios' => [
                ['occupation_id' => $this->oficio('Vigilante')->id, 'relacion' => 'busca'],
                ['occupation_id' => $this->oficio('Conductor')->id, 'relacion' => 'experiencia'],
            ],
        ])->assertStatus(200);

        $this->assertTrue($respuesta->json('data.busca_empleo'));
        $this->assertSame(5, $respuesta->json('data.anios_experiencia'));
        $this->assertCount(2, $respuesta->json('data.oficios'));

        $this->assertDatabaseHas('voter_profiles', [
            'voter_id' => $this->voter->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('voter_occupations', [
            'voter_id' => $this->voter->id,
            'occupation_id' => $this->oficio('Vigilante')->id,
            'relacion' => 'busca',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_el_segundo_put_reemplaza_el_conjunto_de_oficios_y_no_acumula(): void
    {
        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'busca_empleo' => true,
            'oficios' => [
                ['occupation_id' => $this->oficio('Vigilante')->id, 'relacion' => 'busca'],
                ['occupation_id' => $this->oficio('Conductor')->id, 'relacion' => 'busca'],
            ],
        ])->assertStatus(200);

        $respuesta = $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'busca_empleo' => true,
            'oficios' => [
                ['occupation_id' => $this->oficio('Cocina')->id, 'relacion' => 'experiencia'],
            ],
        ])->assertStatus(200);

        $this->assertCount(1, $respuesta->json('data.oficios'));

        $this->assertSame(1, \App\Models\VoterOccupation::where('voter_id', $this->voter->id)->count());

        $this->assertDatabaseMissing('voter_occupations', [
            'voter_id' => $this->voter->id,
            'occupation_id' => $this->oficio('Vigilante')->id,
        ]);

        // El perfil sigue siendo uno solo: `voter_id` es único y esto es un upsert.
        $this->assertSame(1, \App\Models\VoterProfile::where('voter_id', $this->voter->id)->count());
    }

    public function test_marcar_la_autorizacion_sella_la_fecha_y_desmarcarla_la_limpia(): void
    {
        $respuesta = $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'autoriza_tratamiento_datos' => true,
        ])->assertStatus(200);

        $this->assertNotNull($respuesta->json('data.autorizado_at'));

        $respuesta = $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'autoriza_tratamiento_datos' => false,
        ])->assertStatus(200);

        $this->assertNull($respuesta->json('data.autorizado_at'));
        $this->assertDatabaseHas('voter_profiles', [
            'voter_id' => $this->voter->id,
            'autoriza_tratamiento_datos' => false,
            'autorizado_at' => null,
        ]);
    }

    public function test_un_oficio_inexistente_da_422_y_nunca_ensucia_el_catalogo(): void
    {
        $antes = Occupation::count();

        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'busca_empleo' => true,
            'oficios' => [
                ['occupation_id' => 999999, 'relacion' => 'busca'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['oficios.0.occupation_id']);

        $this->assertSame($antes, Occupation::count());
        $this->assertDatabaseMissing('voter_profiles', ['voter_id' => $this->voter->id]);
    }

    public function test_una_relacion_desconocida_da_422(): void
    {
        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'oficios' => [
                ['occupation_id' => $this->oficio('Vigilante')->id, 'relacion' => 'quizas'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['oficios.0.relacion']);
    }

    public function test_los_anios_de_experiencia_tienen_rango(): void
    {
        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'anios_experiencia' => 120,
        ])->assertStatus(422)->assertJsonValidationErrors(['anios_experiencia']);

        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'anios_experiencia' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['anios_experiencia']);
    }

    public function test_un_perfil_sin_oficios_pero_con_nota_es_valido(): void
    {
        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", [
            'busca_empleo' => true,
            'notas' => 'Lo que salga.',
            'oficios' => [],
        ])->assertStatus(200)
            ->assertJsonPath('data.busca_empleo', true)
            ->assertJsonCount(0, 'data.oficios');
    }

    public function test_un_votante_sin_perfil_devuelve_data_nula_y_no_404(): void
    {
        $this->getJson("/api/v1/voters/{$this->voter->id}/perfil")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    private function oficio(string $nombre): Occupation
    {
        return Occupation::where('nombre', $nombre)->firstOrFail();
    }
}

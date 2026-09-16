<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Occupation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterOccupation;
use App\Models\VoterProfile;
use Database\Seeders\OccupationsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /voters/perfiles` — la bolsa de empleo (Spec 0094).
 */
class BolsaDeEmpleoTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OccupationsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        [$this->user] = $this->createTenantWithUser(['view_voter_profiles', 'view_voters'], $this->tenant);

        $this->actingAsTenantUser($this->user);
    }

    public function test_la_ruta_literal_no_la_come_el_binding_de_voters(): void
    {
        // Spec 0006: declarada después de `/voters/{voter}`, esto sería un intento
        // de resolver un votante con id «perfiles» y respondería 404.
        $this->getJson('/api/v1/voters/perfiles')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page']]);
    }

    public function test_el_sinonimo_celador_devuelve_a_los_de_vigilante(): void
    {
        $vigilante = $this->conOficio('Vigilante', 'busca');

        $respuesta = $this->getJson('/api/v1/voters/perfiles?oficio=celador')
            ->assertStatus(200);

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertSame($vigilante->id, $respuesta->json('data.0.votante.id'));
    }

    public function test_el_sinonimo_resuelve_sin_importar_mayusculas_ni_acentos(): void
    {
        $this->conOficio('Mecánica', 'busca');

        $this->getJson('/api/v1/voters/perfiles?oficio='.urlencode('  MECÁNICO  '))
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_el_filtro_por_id_de_oficio_funciona_igual(): void
    {
        $this->conOficio('Vigilante', 'busca');
        $id = Occupation::where('nombre', 'Vigilante')->value('id');

        $this->getJson("/api/v1/voters/perfiles?oficio={$id}")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_un_texto_que_no_resuelve_devuelve_vacio_y_no_error(): void
    {
        $this->conOficio('Vigilante', 'busca');

        $this->getJson('/api/v1/voters/perfiles?oficio=astronauta')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_busca_y_experiencia_se_distinguen(): void
    {
        $quienBusca = $this->conOficio('Contable', 'busca');
        $quienSabe = $this->conOficio('Contable', 'experiencia');

        $respuesta = $this->getJson('/api/v1/voters/perfiles?oficio=contador&relacion=busca')
            ->assertStatus(200);

        $this->assertSame(1, $respuesta->json('meta.total'));
        $this->assertSame($quienBusca->id, $respuesta->json('data.0.votante.id'));

        $respuesta = $this->getJson('/api/v1/voters/perfiles?oficio=contador&relacion=experiencia')
            ->assertStatus(200);

        $this->assertSame($quienSabe->id, $respuesta->json('data.0.votante.id'));
    }

    public function test_filtra_por_busca_empleo(): void
    {
        $this->perfil(['busca_empleo' => true]);
        $this->perfil(['busca_empleo' => false]);

        $this->getJson('/api/v1/voters/perfiles?busca_empleo=1')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/voters/perfiles?busca_empleo=0')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_filtra_por_texto_en_las_notas(): void
    {
        $this->perfil(['notas' => 'Pidió algo de noche, cerca de El Salado.']);
        $this->perfil(['notas' => 'Solo fines de semana.']);

        $this->getJson('/api/v1/voters/perfiles?q=Salado')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_el_votante_borrado_no_sale_en_la_bolsa(): void
    {
        $votante = $this->conOficio('Vigilante', 'busca');

        $votante->delete();

        $this->getJson('/api/v1/voters/perfiles')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_el_buscador_no_dispara_una_consulta_por_votante(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->conOficio('Vigilante', 'busca');
        }

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $respuesta = $this->getJson('/api/v1/voters/perfiles?per_page=50')->assertStatus(200);

        $this->assertSame(8, $respuesta->json('meta.total'));

        // Con eager loading el coste es constante: contar, traer los perfiles y
        // una consulta por relación cargada. Sin él serían dos más por perfil.
        $this->assertLessThanOrEqual(
            12,
            $consultas,
            "El buscador hizo {$consultas} consultas para 8 perfiles: huele a N+1 (Art. VI)."
        );
    }

    /**
     * Un votante del tenant con perfil y un oficio en la relación dada.
     */
    private function conOficio(string $nombre, string $relacion): Voter
    {
        $votante = $this->perfil()->voter;

        VoterOccupation::create([
            'tenant_id' => $this->tenant->id,
            'voter_id' => $votante->id,
            'occupation_id' => Occupation::where('nombre', $nombre)->value('id'),
            'relacion' => $relacion,
        ]);

        return $votante;
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function perfil(array $atributos = []): VoterProfile
    {
        $votante = Voter::factory()->forTenant($this->tenant)->create();

        return VoterProfile::factory()
            ->paraVotante($votante)
            ->create($atributos)
            ->setRelation('voter', $votante);
    }
}

<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Meeting;
use App\Models\Occupation;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VoterOccupation;
use App\Models\VoterProfile;
use Database\Seeders\OccupationsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El perfil laboral es dato personal y no sale a la calle (Spec 0094, Art. VII).
 *
 * Quien tiene el QR de una reunión tiene una credencial para hacer check-in, no
 * para saber quién de esa campaña está buscando trabajo.
 */
class PerfilFueraDeRutasPublicasTest extends TestCase
{
    private Tenant $tenant;

    private Voter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 500)]);

        $this->seed(OccupationsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->voter = Voter::factory()->forTenant($this->tenant)->create(['cedula' => '79000001']);

        VoterProfile::factory()->paraVotante($this->voter)->create([
            'busca_empleo' => true,
            'notas' => 'Buscando turno de noche.',
        ]);

        VoterOccupation::create([
            'tenant_id' => $this->tenant->id,
            'voter_id' => $this->voter->id,
            'occupation_id' => Occupation::where('nombre', 'Vigilante')->value('id'),
            'relacion' => 'busca',
        ]);
    }

    public function test_las_rutas_del_perfil_exigen_sesion(): void
    {
        $this->getJson('/api/v1/oficios')->assertStatus(401);
        $this->getJson('/api/v1/voters/perfiles')->assertStatus(401);
        $this->getJson("/api/v1/voters/{$this->voter->id}/perfil")->assertStatus(401);
        $this->putJson("/api/v1/voters/{$this->voter->id}/perfil", ['busca_empleo' => true])
            ->assertStatus(401);
    }

    public function test_el_check_in_por_qr_no_devuelve_el_perfil(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0094']);

        $cuerpo = $this->postJson("/api/v1/meetings/check-in/{$meeting->qr_code}", [
            'cedula' => $this->voter->cedula,
            'nombres' => $this->voter->nombres,
            'apellidos' => $this->voter->apellidos,
        ])->assertStatus(201)->getContent();

        $this->assertSinRastroDelPerfil($cuerpo);
    }

    public function test_la_vista_publica_de_la_reunion_no_devuelve_el_perfil(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0094-B']);

        $cuerpo = $this->getJson("/api/v1/meetings/public/{$meeting->qr_code}")
            ->assertStatus(200)->getContent();

        $this->assertSinRastroDelPerfil($cuerpo);
    }

    public function test_el_verify_document_publico_no_devuelve_el_perfil(): void
    {
        $meeting = Meeting::factory()->forTenant($this->tenant)->create(['qr_code' => 'QR-0094-C']);

        $cuerpo = $this->getJson(
            "/api/v1/meetings/public/{$meeting->qr_code}/verify-document?cedula={$this->voter->cedula}"
        )->getContent();

        $this->assertSinRastroDelPerfil($cuerpo);
    }

    private function assertSinRastroDelPerfil(string $cuerpo): void
    {
        foreach (['busca_empleo', 'perfil', 'oficios', 'Buscando turno de noche', 'anios_experiencia'] as $rastro) {
            $this->assertStringNotContainsString(
                $rastro,
                $cuerpo,
                "La respuesta pública dejó ver «{$rastro}» del perfil laboral."
            );
        }
    }
}

<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14ServiceToken;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Quién puede publicar actas y a nombre de qué campaña (Spec 0061 · Parte A).
 *
 * El lector corre en la máquina de alguien con el token en un `.env`. La
 * pregunta que estas pruebas responden es qué pasa cuando ese archivo se
 * filtra o cuando alguien intenta publicar sin él.
 */
class E14AuthTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function acta(array $cambios = []): array
    {
        return array_replace([
            'tipo' => 'alcaldia',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'archivo_hash' => str_repeat('e', 64),
            'suma_declarada' => 60,
            'votos_urna' => 60,
            'votantes_e11' => 60,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [['numero' => 1, 'nombre' => 'JORGE BOLIVAR', 'votos' => 60]],
        ], $cambios);
    }

    private function tokenDeServicio(Tenant $tenant): string
    {
        Artisan::call('e14:token', ['tenant' => $tenant->slug]);

        preg_match('/e14_[0-9a-f]{48}/', Artisan::output(), $coincidencias);

        $this->assertNotEmpty($coincidencias, 'El comando debe imprimir el token en claro una vez.');

        return $coincidencias[0];
    }

    public function test_sin_credencial_no_se_publica_ni_se_escribe_nada(): void
    {
        $this->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(401)
            ->assertJsonPath('error', 'UNAUTHENTICATED');

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_un_token_inventado_no_entra(): void
    {
        $this->withHeader('Authorization', 'Bearer '.E14ServiceToken::PREFIJO.str_repeat('0', 48))
            ->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(401);

        $this->assertSame(0, E14Acta::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_un_jwt_corrupto_tampoco(): void
    {
        $this->withHeader('Authorization', 'Bearer no.es.un.jwt')
            ->getJson('/api/v1/e14/actas')
            ->assertStatus(401);
    }

    public function test_el_token_del_comando_publica_de_verdad(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-lectora']);
        $token = $this->tokenDeServicio($tenant);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(201)
            ->assertJsonPath('data.estado', 'procesada');

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        $this->assertSame($tenant->id, $acta->tenant_id, 'El tenant sale de la credencial.');
    }

    public function test_el_cuerpo_de_la_peticion_no_puede_elegir_la_campana(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-duena']);
        $otro = Tenant::factory()->create(['slug' => 'campana-ajena']);
        $token = $this->tokenDeServicio($tenant);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/e14/actas', $this->acta(['tenant_id' => $otro->id]))
            ->assertStatus(201);

        $acta = E14Acta::withoutGlobalScope(TenantScope::class)->first();

        $this->assertSame(
            $tenant->id,
            $acta->tenant_id,
            'Un campo del cuerpo no puede mover el acta a otra campaña.'
        );
    }

    public function test_rotar_el_token_deja_al_anterior_sin_efecto(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-rotada']);
        $viejo = $this->tokenDeServicio($tenant);

        Artisan::call('e14:token', ['tenant' => $tenant->slug, '--rotate' => true]);
        preg_match('/e14_[0-9a-f]{48}/', Artisan::output(), $coincidencias);
        $nuevo = $coincidencias[0];

        $this->assertNotSame($viejo, $nuevo);

        $this->withHeader('Authorization', 'Bearer '.$viejo)
            ->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$nuevo)
            ->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(201);
    }

    public function test_el_token_del_lector_no_abre_el_resto_del_sistema(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-limitada']);
        $token = $this->tokenDeServicio($tenant);

        // `e14.auth` solo cubre las rutas de escrutinio; el resto sigue pidiendo
        // un JWT, que este token no es.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/meetings')
            ->assertStatus(401);
    }

    public function test_el_panel_entra_con_su_jwt_de_siempre(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $jwt] = $this->createTenantWithUser([Permissions::VIEW_E14], $tenant);

        $this->actingAsTenantUser($user, $jwt)
            ->getJson('/api/v1/e14/actas')
            ->assertStatus(200);
    }

    public function test_un_token_de_un_usuario_borrado_no_resuelve(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-sin-usuario']);
        $token = $this->tokenDeServicio($tenant);

        $servicio = E14ServiceToken::withoutGlobalScope(TenantScope::class)->first();
        User::withoutGlobalScope(TenantScope::class)->where('id', $servicio->user_id)->forceDelete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/e14/actas', $this->acta())
            ->assertStatus(401);
    }
}

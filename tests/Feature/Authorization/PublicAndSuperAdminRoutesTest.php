<?php

namespace Tests\Feature\Authorization;

use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/**
 * No-regresión del enforcement (Spec 0005): añadir `permission:`/`role:` a las
 * rutas del grupo tenant no puede afectar a las rutas públicas ni al grupo
 * `superadmin`, que viven fuera de ese grupo.
 */
class PublicAndSuperAdminRoutesTest extends TestCase
{
    public function test_el_login_publico_sigue_sin_autorizacion(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->forTenant($tenant)->create(['email' => 'publico@example.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'publico@example.com',
            'password' => 'password',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_el_check_in_publico_por_qr_sigue_sin_autorizacion(): void
    {
        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create(['qr_code' => 'QR-ABIERTO-1']);

        $this->getJson('/api/v1/meetings/check-in/QR-ABIERTO-1')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meeting->id);
    }

    public function test_me_sigue_accesible_para_cualquier_autenticado(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_el_grupo_superadmin_sigue_reservado_al_super_admin(): void
    {
        Tenant::factory()->create();

        $this->actingAsSuperAdmin();
        $this->getJson('/api/v1/tenants')->assertStatus(200);
    }

    public function test_un_usuario_de_tenant_sigue_sin_entrar_al_grupo_superadmin(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/tenants')
            ->assertStatus(403);
    }

    public function test_una_ruta_protegida_sin_token_responde_401_no_403(): void
    {
        // El orden importa: `jwt.auth` corre antes que `permission:`, así que un
        // anónimo recibe 401 y no una pista de qué permiso le falta.
        $this->getJson('/api/v1/voters')->assertStatus(401);
    }
}

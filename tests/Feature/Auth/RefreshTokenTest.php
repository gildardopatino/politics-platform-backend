<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use Tests\TestCase;

/**
 * `POST /refresh` es la pieza sobre la que se apoya el refresh proactivo del
 * cliente (Spec 0018). Hasta ahora nadie lo probaba: el frontend renovaba
 * fiándose de un middleware que en realidad nunca corría.
 */
class RefreshTokenTest extends TestCase
{
    public function test_con_un_token_valido_devuelve_un_token_nuevo(): void
    {
        [$user, $token] = $this->createTenantWithUser();

        $response = $this->actingAsTenantUser($user, $token)->postJson('/api/v1/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id']])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.id', $user->id);

        $nuevo = $response->json('access_token');

        $this->assertIsString($nuevo);
        $this->assertCount(3, explode('.', $nuevo), 'Debe ser un JWT de tres segmentos.');
    }

    public function test_el_token_devuelto_sirve_para_autenticar(): void
    {
        // Lo que importa no es que devuelva "un string", sino que el cliente
        // pueda seguir trabajando con él sin volver a iniciar sesión.
        [$user, $token] = $this->createTenantWithUser();

        $nuevo = $this->actingAsTenantUser($user, $token)
            ->postJson('/api/v1/refresh')
            ->assertStatus(200)
            ->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$nuevo)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_con_un_token_invalido_devuelve_401(): void
    {
        $this->withHeader('Authorization', 'Bearer token-que-no-es-un-jwt')
            ->postJson('/api/v1/refresh')
            ->assertStatus(401);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $this->postJson('/api/v1/refresh')->assertStatus(401);
    }

    public function test_el_refresh_conserva_el_tenant_del_usuario(): void
    {
        // El JWT lleva `tenant_id` en sus claims; si el refresh lo perdiera, el
        // usuario quedaría sin contexto de tenant tras renovar.
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser(['view_meetings'], $tenant);

        $nuevo = $this->actingAsTenantUser($user, $token)
            ->postJson('/api/v1/refresh')
            ->assertStatus(200)
            ->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$nuevo)
            ->getJson('/api/v1/meetings')
            ->assertStatus(200);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_con_credenciales_validas_devuelve_token_jwt(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create([
            'email' => 'candidato@example.com',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'candidato@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'user' => ['id', 'email'],
            ])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('user.id', $user->id);

        $this->assertIsString($response->json('access_token'));
        // Un JWT son tres segmentos separados por punto.
        $this->assertCount(3, explode('.', $response->json('access_token')));
    }

    public function test_login_con_credenciales_invalidas_devuelve_401(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->forTenant($tenant)->create(['email' => 'candidato@example.com']);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'candidato@example.com',
            'password' => 'password-incorrecta',
        ]);

        $response->assertStatus(401);
    }

    public function test_me_con_token_devuelve_el_usuario_autenticado(): void
    {
        [$user, $token] = $this->createTenantWithUser();

        $response = $this->actingAsTenantUser($user, $token)->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}

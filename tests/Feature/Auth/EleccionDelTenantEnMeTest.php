<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use Tests\TestCase;

/**
 * La elección de la campaña, resuelta por el servidor (Spec 0093 · Parte C).
 *
 * El frontend quita los selectores de tipo y necesita saber cuál es la elección
 * del tenant para enseñarla como etiqueta fija. Esa traducción
 * `tipo_cargo → tipo E-14` ya vive en `Tenant::ELECCION_POR_CARGO` y no puede
 * duplicarse en TypeScript (Art. IV): sale servida en `/me`, junto al
 * `tipo_cargo` que el cliente ya usa para el rótulo en español.
 */
class EleccionDelTenantEnMeTest extends TestCase
{
    public function test_me_expone_la_eleccion_del_tenant(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Gobernacion']);
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.tenant.tipo_cargo', 'Gobernacion')
            ->assertJsonPath('data.tenant.tipo_eleccion', 'gobernacion');
    }

    public function test_un_diputado_escruta_asamblea_departamental(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Diputado']);
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.tenant.tipo_eleccion', 'asamblea_departamental');
    }

    public function test_el_tenant_de_cargo_otro_no_tiene_eleccion(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Otro']);
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.tenant.tipo_eleccion', null);
    }
}

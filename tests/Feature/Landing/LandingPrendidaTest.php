<?php

namespace Tests\Feature\Landing;

use App\Models\Tenant;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Prender el flag devuelve la landing (Spec 0085 · RF-4).
 *
 * Es la mitad que hace honesta a la otra: apagar por las bravas —borrando rutas—
 * también dejaría la suite en verde, y esta spec prometió que la Fase 1 es
 * **reversible**. Con `LANDING_HABILITADA=true` las rutas vuelven a existir tal
 * como estaban.
 *
 * El flag se pone en el entorno **antes** de que arranque la aplicación, no con
 * `config()` dentro de la prueba: las rutas se registran al bootear, así que
 * cambiar la config después no las traería de vuelta.
 */
class LandingPrendidaTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('LANDING_HABILITADA=true');
        $_ENV['LANDING_HABILITADA'] = 'true';
        $_SERVER['LANDING_HABILITADA'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('LANDING_HABILITADA');
        unset($_ENV['LANDING_HABILITADA'], $_SERVER['LANDING_HABILITADA']);

        parent::tearDown();
    }

    public function test_el_flag_lee_el_entorno(): void
    {
        $this->assertTrue(config('landing.habilitada'));
    }

    public function test_los_endpoints_publicos_vuelven_a_existir(): void
    {
        Tenant::factory()->create(['slug' => 'la-campana']);

        // 200 y no 404: la ruta está, el controlador responde y el tenant se
        // resuelve por su cabecera, como siempre.
        $this->getJson('/api/v1/landingpage/banners', ['X-Tenant-Slug' => 'la-campana'])
            ->assertStatus(200);
    }

    public function test_el_admin_de_la_landing_vuelve_a_existir(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::MANAGE_LANDINGPAGE], $tenant);

        $this->actingAsTenantUser($user)
            ->getJson('/api/v1/landingpage/admin/banners')
            ->assertStatus(200);
    }

    public function test_el_admin_de_la_landing_sigue_pidiendo_su_permiso(): void
    {
        // Prender el flag devuelve la landing como estaba, con su puerta puesta:
        // no la abre para todo el mundo.
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::VIEW_VOTERS], $tenant);

        $this->actingAsTenantUser($user)
            ->getJson('/api/v1/landingpage/admin/banners')
            ->assertStatus(403);
    }
}

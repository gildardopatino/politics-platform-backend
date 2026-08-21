<?php

namespace Tests\Feature\Landing;

use App\Models\Tenant;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * La landing, apagada por defecto (Spec 0085 · Fase 1).
 *
 * La landing —banners, biografía, propuestas, eventos, galería, testimonios,
 * feed de redes, voluntarios, contacto— es **vitrina pública**: no toca ninguna
 * decisión de campaña. Se retira del producto en dos pasos, y este es el
 * primero: apagarla tras un flag, sin borrar nada.
 *
 * Con `landing.habilitada` en `false` —el defecto— sus rutas **no se registran**.
 * Es más que un 404 dentro del controlador: una ruta que no existe no se puede
 * explotar, y quitar endpoints públicos que nadie usa es quitar superficie de
 * ataque (Art. VII).
 *
 * Lo que estas pruebas cuidan tanto como el apagado es lo de al lado: apagar un
 * módulo grande es fácil de hacer de más. Por eso media clase comprueba que CRM,
 * escrutinio y análisis siguen exactamente igual.
 */
class LandingApagadaTest extends TestCase
{
    /** Las nueve rutas públicas que la landing exponía sin autenticación. */
    public static function endpointsPublicos(): array
    {
        return [
            'banners' => ['GET', '/api/v1/landingpage/banners'],
            'biografía' => ['GET', '/api/v1/landingpage/biografia'],
            'propuestas' => ['GET', '/api/v1/landingpage/propuestas'],
            'eventos' => ['GET', '/api/v1/landingpage/eventos'],
            'galería' => ['GET', '/api/v1/landingpage/galeria'],
            'testimonios' => ['GET', '/api/v1/landingpage/testimonios'],
            'feed de redes' => ['GET', '/api/v1/landingpage/social-feed'],
            'voluntarios' => ['POST', '/api/v1/landingpage/voluntarios'],
            'contacto' => ['POST', '/api/v1/landingpage/contacto'],
        ];
    }

    /** El admin de la landing, y el sync de redes que la alimentaba. */
    public static function endpointsDeAdmin(): array
    {
        return [
            'banners' => ['GET', '/api/v1/landingpage/admin/banners'],
            'propuestas' => ['GET', '/api/v1/landingpage/admin/propuestas'],
            'eventos' => ['GET', '/api/v1/landingpage/admin/eventos'],
            'galería' => ['GET', '/api/v1/landingpage/admin/galeria'],
            'testimonios' => ['GET', '/api/v1/landingpage/admin/testimonios'],
            'feed de redes' => ['GET', '/api/v1/landingpage/admin/social-feed'],
            'biografía' => ['GET', '/api/v1/landingpage/admin/biografia'],
            'ajustes de redes' => ['GET', '/api/v1/settings/social-media'],
        ];
    }

    // ------------------------------------------------------- el apagado

    public function test_el_flag_viene_apagado_de_fabrica(): void
    {
        // Si el defecto fuera `true`, la spec no habría retirado nada: habría
        // dejado un interruptor que nadie apaga.
        $this->assertFalse(config('landing.habilitada'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpointsPublicos')]
    public function test_los_endpoints_publicos_de_la_landing_no_existen(string $metodo, string $ruta): void
    {
        $this->json($metodo, $ruta, [], ['X-Tenant-Slug' => 'la-campana'])
            ->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpointsDeAdmin')]
    public function test_el_admin_de_la_landing_tampoco_existe(string $metodo, string $ruta): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::MANAGE_LANDINGPAGE], $tenant);

        // Con permiso y todo: no es que falte autorización, es que no hay ruta.
        $this->actingAsTenantUser($user)->json($metodo, $ruta)->assertStatus(404);
    }

    public function test_el_comando_de_sync_avisa_en_vez_de_correr(): void
    {
        $this->artisan('social:sync')
            ->expectsOutputToContain('La landing esta apagada')
            ->assertExitCode(0);
    }

    // --------------------------------------- el resto del producto sigue

    public function test_el_crm_de_votantes_sigue_en_pie(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::VIEW_VOTERS], $tenant);

        $this->actingAsTenantUser($user)->getJson('/api/v1/voters')->assertStatus(200);
    }

    public function test_el_escrutinio_sigue_en_pie(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::VIEW_E14], $tenant);

        $this->actingAsTenantUser($user)->getJson('/api/v1/e14/actas')->assertStatus(200);
        $this->actingAsTenantUser($user)->getJson('/api/v1/e14/consolidado')->assertStatus(200);
    }

    public function test_las_reuniones_y_su_check_in_publico_siguen_en_pie(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->createTenantWithUser([Permissions::VIEW_MEETINGS], $tenant);

        $this->actingAsTenantUser($user)->getJson('/api/v1/meetings')->assertStatus(200);

        // El check-in por QR es público, como lo era la landing, y **no** se
        // apaga: ahí sí hay una decisión de campaña detrás. Un 422 pidiendo la
        // cédula es la prueba de que la ruta sigue viva —un 404 sería la de que
        // se la llevó por delante el apagado—.
        $this->postJson('/api/v1/meetings/check-in/QR-QUE-NO-EXISTE', [])
            ->assertStatus(422);
    }

    public function test_el_permiso_de_la_landing_sigue_en_el_catalogo(): void
    {
        // Apagar no es borrar: el permiso se queda sembrado para que prender el
        // flag devuelva la landing entera, roles incluidos (Fase 2 lo quitará).
        $this->assertContains(Permissions::MANAGE_LANDINGPAGE, Permissions::all());
    }
}

<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\CheckTenantExpiration;
use App\Http\Middleware\EnsureTenant;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tymon\JWTAuth\Http\Middleware\Authenticate as TymonAuthenticate;

/**
 * Reordenar la prioridad de middleware (Spec 0004) es un cambio global: estas
 * pruebas fijan el orden resultante y verifican que las rutas públicas y las de
 * super admin siguen funcionando.
 */
class MiddlewarePriorityTest extends TestCase
{
    public function test_tenant_se_resuelve_antes_que_el_binding_implicito(): void
    {
        $middleware = $this->middlewareDe('GET', 'api/v1/meetings/{meeting}');

        $orden = array_search(EnsureTenant::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);

        $this->assertNotFalse($orden, 'EnsureTenant debe estar en la cadena.');
        $this->assertNotFalse($bindings, 'SubstituteBindings debe estar en la cadena.');
        $this->assertLessThan(
            $bindings,
            $orden,
            'EnsureTenant debe correr ANTES de SubstituteBindings o TenantScope no filtra el binding.'
        );

        // Orden completo esperado en una ruta de tenant.
        $this->assertSame(
            [$this->claseDeJwtAuth(), EnsureTenant::class, CheckTenantExpiration::class, SubstituteBindings::class],
            array_values(array_intersect($middleware, [
                $this->claseDeJwtAuth(),
                EnsureTenant::class,
                CheckTenantExpiration::class,
                SubstituteBindings::class,
            ]))
        );
    }

    public function test_jwt_se_resuelve_antes_que_el_binding_en_rutas_de_super_admin(): void
    {
        $middleware = $this->middlewareDe('GET', 'api/v1/tenants/{tenant}');

        $this->assertLessThan(
            array_search(SubstituteBindings::class, $middleware, true),
            array_search($this->claseDeJwtAuth(), $middleware, true)
        );
    }

    public function test_el_login_publico_sigue_funcionando(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->forTenant($tenant)->create(['email' => 'publico@example.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'publico@example.com',
            'password' => 'password',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_el_check_in_publico_por_qr_sigue_funcionando(): void
    {
        $tenant = Tenant::factory()->create();
        $meeting = Meeting::factory()->forTenant($tenant)->create(['qr_code' => 'QR-PUBLICO-123']);

        // Sin token: es una ruta pública, fuera del grupo jwt.auth/tenant.
        $this->getJson('/api/v1/meetings/check-in/QR-PUBLICO-123')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $meeting->id);
    }

    public function test_una_ruta_de_super_admin_sigue_distinguiendo_quien_entra(): void
    {
        Tenant::factory()->create();

        $this->actingAsSuperAdmin();
        $this->getJson('/api/v1/tenants')->assertStatus(200);

        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/tenants')
            ->assertStatus(403);
    }

    /**
     * Clase real detrás del alias `jwt.auth`.
     *
     * Se resuelve en runtime en vez de darla por hecha: tymon/jwt-auth registra
     * ese alias en el boot de su service provider, después de
     * `bootstrap/app.php`, así que la última palabra la tiene el paquete.
     */
    private function claseDeJwtAuth(): string
    {
        $alias = app(Router::class)->getMiddleware()['jwt.auth'] ?? null;

        $this->assertNotNull($alias, 'El alias jwt.auth debe estar registrado.');
        $this->assertSame(
            TymonAuthenticate::class,
            $alias,
            'jwt.auth apunta a una clase inesperada; revisa la lista de prioridad.'
        );

        return $alias;
    }

    /**
     * Cadena de middleware ya ordenada para una ruta concreta.
     *
     * @return array<int, string>
     */
    private function middlewareDe(string $method, string $uri): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        $this->assertNotNull($route, "No existe la ruta {$method} {$uri}.");

        return app(Router::class)->gatherRouteMiddleware($route);
    }
}

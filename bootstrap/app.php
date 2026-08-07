<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind nginx + Cloudflare Tunnel: trust forwarded headers so Laravel
        // detects the real client IP, host and HTTPS scheme.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            // tymon/jwt-auth registra este mismo alias en el boot de su service
            // provider, así que declararlo aquí apuntando a otra clase no servía
            // de nada: ganaba el suyo (Spec 0018). Se declara explícito para que
            // el alias diga la verdad.
            'jwt.auth' => \Tymon\JWTAuth\Http\Middleware\Authenticate::class,
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'superadmin' => \App\Http\Middleware\CheckSuperAdmin::class,
            'tenant.active' => \App\Http\Middleware\CheckTenantExpiration::class,
            // Autorización por ruta (Spec 0005). spatie/laravel-permission no
            // registra sus aliases solo. `role` usa middleware propio porque el
            // de Spatie no pasa por el Gate y se saltaría el bypass de super
            // admin (ver App\Http\Middleware\EnsureRole).
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            // Webhook de Registraduría: autentica por secreto de tenant y fija
            // `current_tenant_id` sin que haya sesión (Spec 0030).
            'webhook.registraduria' => \App\Http\Middleware\VerifyRegistraduriaWebhook::class,
        ]);

        // Aislamiento multi-tenant (Constitución, Art. III / Spec 0004).
        //
        // `SubstituteBindings` viene del grupo `api` y, sin esta lista, corría
        // ANTES que `jwt.auth`/`tenant`: el binding implícito (`{meeting}`,
        // `{commitment}`, `{voter}`, ...) se resolvía sin `current_tenant_id` en
        // el contenedor, `TenantScope` no filtraba y el controlador recibía el
        // modelo de otro tenant (GET/PUT/DELETE por id devolvían datos ajenos).
        //
        // Al declarar la prioridad, EnsureTenant fija el tenant antes de que se
        // resuelva el binding: el scope filtra y `firstOrFail` da 404. Es la
        // lista por defecto de Laravel con los tres middleware propios
        // insertados justo antes de SubstituteBindings.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            // `jwt.auth` (ver el alias arriba).
            \Tymon\JWTAuth\Http\Middleware\Authenticate::class,
            \App\Http\Middleware\EnsureTenant::class,
            \App\Http\Middleware\CheckTenantExpiration::class,
            // Autorización después del tenant: los roles del usuario están
            // scopeados por `TenantScope`, así que sin `current_tenant_id`
            // enlazado no se resolverían los del tenant correcto.
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
            \App\Http\Middleware\EnsureRole::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // Permiso/rol insuficiente (Spec 0005). Spatie lanza su
        // UnauthorizedException con un mensaje en inglés; la API responde en
        // español (Constitución, Art. IX) y con una forma estable.
        $exceptions->render(function (UnauthorizedException $e, $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'error' => 'FORBIDDEN',
            ], 403);
        });
    })->create();

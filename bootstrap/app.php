<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

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
            'jwt.auth' => \App\Http\Middleware\JwtMiddleware::class,
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'superadmin' => \App\Http\Middleware\CheckSuperAdmin::class,
            'tenant.active' => \App\Http\Middleware\CheckTenantExpiration::class,
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
            // Ojo: tymon/jwt-auth re-registra el alias `jwt.auth` en el boot de
            // su service provider, así que en runtime apunta a SU Authenticate y
            // no a JwtMiddleware. Se listan ambos para que el orden sea correcto
            // apunte a donde apunte el alias.
            \Tymon\JWTAuth\Http\Middleware\Authenticate::class,
            \App\Http\Middleware\JwtMiddleware::class,
            \App\Http\Middleware\EnsureTenant::class,
            \App\Http\Middleware\CheckTenantExpiration::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `role:` (Spec 0005).
 *
 * Reemplaza a `Spatie\Permission\Middleware\RoleMiddleware` por una razón
 * concreta: aquel resuelve con `hasAnyRole()`, que **no pasa por el Gate**, así
 * que el `Gate::before` que da paso al super admin no lo cubriría y un super
 * admin recibiría 403 en las rutas de `role:admin`.
 *
 * Lanza la misma `UnauthorizedException` que los middleware de Spatie para que
 * el 403 salga con el mismo cuerpo JSON (ver el renderable en bootstrap/app.php).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $roles, ?string $guard = null): Response
    {
        $user = Auth::guard($guard)->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        // Mismo criterio que Gate::before: el super admin global pasa siempre.
        if ($user->is_super_admin) {
            return $next($request);
        }

        $rolesRequeridos = explode('|', $roles);

        if (! $user->hasAnyRole($rolesRequeridos)) {
            throw UnauthorizedException::forRoles($rolesRequeridos);
        }

        return $next($request);
    }
}

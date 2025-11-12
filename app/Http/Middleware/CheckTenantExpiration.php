<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantExpiration
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip check if no authenticated user or if user is superadmin (tenant_id = null)
        if (!$user || is_null($user->tenant_id)) {
            return $next($request);
        }

        // Get the tenant
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found.',
                'error' => 'TENANT_NOT_FOUND'
            ], 404);
        }

        // Check if tenant hasn't started yet
        if ($tenant->isNotStarted()) {
            return response()->json([
                'message' => 'Su cuenta aún no está activa. Por favor, comuníquese con el administrador del sistema al correo ' . config('app.admin_email', 'admin@appcore.com.co'),
                'error' => 'TENANT_NOT_STARTED',
                'admin_email' => config('app.admin_email', 'admin@appcore.com.co'),
                'start_date' => $tenant->start_date?->format('Y-m-d H:i:s'),
            ], 403);
        }

        // Check if tenant is expired
        if ($tenant->isExpired()) {
            return response()->json([
                'message' => 'Su cuenta ha expirado. Por favor, comuníquese con el administrador del sistema al correo ' . config('app.admin_email', 'admin@appcore.com.co'),
                'error' => 'TENANT_EXPIRED',
                'admin_email' => config('app.admin_email', 'admin@appcore.com.co'),
                'expiration_date' => $tenant->expiration_date?->format('Y-m-d H:i:s'),
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Super admins don't need tenant
        if ($user->is_super_admin) {
            app()->instance('tenant', null);
            return $next($request);
        }

        if (!$user->tenant_id) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        $tenant = \App\Models\Tenant::find($user->tenant_id);

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found.'
            ], 404);
        }

        // Bind tenant to container
        app()->instance('tenant', $tenant);

        return $next($request);
    }
}

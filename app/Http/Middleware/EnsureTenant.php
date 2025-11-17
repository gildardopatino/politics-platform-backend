<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Scopes\TenantScope;
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

        // Super admins without tenant can see everything
        if ($user->is_super_admin && is_null($user->tenant_id)) {
            app()->instance('tenant', null);
            app()->instance('current_tenant_id', null); // No filtering for super admin
            return $next($request);
        }

        if (!$user->tenant_id) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        $tenant = Tenant::withoutGlobalScope(TenantScope::class)->find($user->tenant_id);

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found.'
            ], 404);
        }

        // Bind tenant to container
        app()->instance('tenant', $tenant);
        app()->instance('current_tenant_id', $user->tenant_id); // For TenantScope filtering

        return $next($request);
    }
}

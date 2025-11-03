<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantSettingsRequest;
use App\Http\Resources\Api\V1\TenantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    /**
     * Get current tenant settings/configuration
     */
    public function show(): JsonResponse
    {
        $tenant = app('tenant');
        
        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'nombre' => $tenant->nombre,
                'tipo_cargo' => $tenant->tipo_cargo,
                'identificacion' => $tenant->identificacion,
                'logo' => $tenant->logo,
                'theme' => [
                    'sidebar_bg_color' => $tenant->sidebar_bg_color,
                    'sidebar_text_color' => $tenant->sidebar_text_color,
                    'header_bg_color' => $tenant->header_bg_color,
                    'header_text_color' => $tenant->header_text_color,
                    'content_bg_color' => $tenant->content_bg_color,
                    'content_text_color' => $tenant->content_text_color,
                ],
                'hierarchy_settings' => [
                    'hierarchy_mode' => $tenant->hierarchy_mode,
                    'auto_assign_hierarchy' => $tenant->auto_assign_hierarchy,
                    'hierarchy_conflict_resolution' => $tenant->hierarchy_conflict_resolution,
                    'require_hierarchy_config' => $tenant->require_hierarchy_config,
                ]
            ]
        ]);
    }

    /**
     * Update tenant settings/configuration
     */
    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        $tenant = app('tenant');
        
        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        // Only allow tenant users to update their own tenant settings
        $user = $request->user();
        if ($user->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'You can only update your own tenant settings.'
            ], 403);
        }

        $tenant->update($request->validated());

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'nombre' => $tenant->nombre,
                'tipo_cargo' => $tenant->tipo_cargo,
                'identificacion' => $tenant->identificacion,
                'logo' => $tenant->logo,
                'theme' => [
                    'sidebar_bg_color' => $tenant->sidebar_bg_color,
                    'sidebar_text_color' => $tenant->sidebar_text_color,
                    'header_bg_color' => $tenant->header_bg_color,
                    'header_text_color' => $tenant->header_text_color,
                    'content_bg_color' => $tenant->content_bg_color,
                    'content_text_color' => $tenant->content_text_color,
                ],
                'hierarchy_settings' => [
                    'hierarchy_mode' => $tenant->hierarchy_mode,
                    'auto_assign_hierarchy' => $tenant->auto_assign_hierarchy,
                    'hierarchy_conflict_resolution' => $tenant->hierarchy_conflict_resolution,
                    'require_hierarchy_config' => $tenant->require_hierarchy_config,
                ]
            ],
            'message' => 'Tenant settings updated successfully'
        ]);
    }

    /**
     * Check if hierarchy is configured
     */
    public function checkHierarchyConfig(): JsonResponse
    {
        $tenant = app('tenant');
        
        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        $isConfigured = $tenant->hierarchy_mode !== 'disabled';
        $requiresConfig = $tenant->require_hierarchy_config;

        return response()->json([
            'data' => [
                'is_configured' => $isConfigured,
                'requires_configuration' => $requiresConfig,
                'can_create_meetings' => !$requiresConfig || $isConfigured,
                'hierarchy_mode' => $tenant->hierarchy_mode,
                'message' => $isConfigured 
                    ? 'La jerarquía está configurada correctamente.' 
                    : ($requiresConfig 
                        ? 'Debe configurar la jerarquía antes de crear reuniones.' 
                        : 'La jerarquía no está configurada, pero no es obligatoria.')
            ]
        ]);
    }
}

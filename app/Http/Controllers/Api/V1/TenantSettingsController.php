<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantSettingsRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Services\WasabiStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TenantSettingsController extends Controller
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Get current tenant settings/configuration
     */
    public function show(): JsonResponse
    {
        $user = auth('api')->user();
        
        // Check if tenant binding exists (for tenant users)
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
        } elseif ($user->tenant_id) {
            // Fallback: load tenant from user relationship
            $tenant = $user->tenant;
        } else {
            // Superadmin trying to access settings without tenant context
            return response()->json([
                'message' => 'No tenant associated with this user. Superadmin must access tenant settings via /api/v1/tenants/{id}'
            ], 403);
        }
        
        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        // Generate logo URL if exists
        $logoUrl = null;
        if ($tenant->logo) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                $logoUrl = $this->wasabi->getSignedUrl($tenant->logo, $tenant);
            } else {
                $logoUrl = asset('storage/' . $tenant->logo);
            }
        }

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'nombre' => $tenant->nombre,
                'tipo_cargo' => $tenant->tipo_cargo,
                'identificacion' => $tenant->identificacion,
                'logo' => $logoUrl,
                'logo_key' => $tenant->logo, // Original key for reference
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
                ],
                'notification_settings' => [
                    'send_logistics_notifications' => $tenant->send_logistics_notifications,
                ]
            ]
        ]);
    }

    /**
     * Update tenant settings/configuration
     */
    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        Log::info('Tenant settings update request received', [
            'tenant_id' => $request->user()->tenant_id,
            'has_logo' => $request->hasFile('logo'),
            'fields' => $request->except(['logo']),
        ]);

        $user = $request->user();
        
        // Check if tenant binding exists (for tenant users)
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
        } elseif ($user->tenant_id) {
            // Fallback: load tenant from user relationship
            $tenant = $user->tenant;
        } else {
            // Superadmin cannot update settings via this endpoint
            return response()->json([
                'message' => 'No tenant associated with this user. Superadmin must update tenant settings via PUT /api/v1/tenants/{id}'
            ], 403);
        }
        
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

        $data = $request->validated();
        $oldLogo = $tenant->logo;

        // Handle logo upload if provided
        if ($request->hasFile('logo')) {
            Log::info('Processing logo upload', [
                'tenant_id' => $tenant->id,
                'old_logo' => $oldLogo,
                'file_size' => $request->file('logo')->getSize(),
                'file_name' => $request->file('logo')->getClientOriginalName(),
                'mime_type' => $request->file('logo')->getMimeType(),
            ]);

            try {
                // Upload new logo
                $upload = $this->wasabi->uploadFile($request->file('logo'), 'tenants/logos', $tenant);
                $data['logo'] = $upload['key'];
                
                Log::info('Logo uploaded successfully', [
                    'new_key' => $upload['key'],
                ]);

                // Delete old logo after successful upload
                if ($oldLogo) {
                    try {
                        $this->wasabi->deleteFile($oldLogo, $tenant);
                        Log::info('Old logo deleted', ['old_key' => $oldLogo]);
                    } catch (\Exception $e) {
                        Log::warning('Could not delete old logo', [
                            'old_key' => $oldLogo,
                            'error' => $e->getMessage()
                        ]);
                        // Continue anyway, not critical
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error uploading logo', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'message' => 'Error al subir el logo: ' . $e->getMessage()
                ], 500);
            }
        }

        // Update tenant with validated data
        $tenant->update($data);
        $tenant->refresh();

        // Generate logo URL if exists
        $logoUrl = null;
        if ($tenant->logo) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                try {
                    $logoUrl = $this->wasabi->getSignedUrl($tenant->logo, $tenant);
                } catch (\Exception $e) {
                    Log::error('Error generating logo URL', [
                        'logo_key' => $tenant->logo,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $logoUrl = asset('storage/' . $tenant->logo);
            }
        }

        Log::info('Tenant settings updated successfully', [
            'tenant_id' => $tenant->id,
            'new_logo_key' => $tenant->logo,
            'updated_fields' => array_keys($data),
        ]);

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'nombre' => $tenant->nombre,
                'tipo_cargo' => $tenant->tipo_cargo,
                'identificacion' => $tenant->identificacion,
                'logo' => $logoUrl,
                'logo_key' => $tenant->logo,
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
                ],
                'notification_settings' => [
                    'send_logistics_notifications' => $tenant->send_logistics_notifications,
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
        $user = auth('api')->user();
        
        // Check if tenant binding exists (for tenant users)
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
        } elseif ($user->tenant_id) {
            // Fallback: load tenant from user relationship
            $tenant = $user->tenant;
        } else {
            // Superadmin trying to check hierarchy without tenant context
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }
        
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

    /**
     * Delete tenant logo
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Check if tenant binding exists (for tenant users)
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
        } elseif ($user->tenant_id) {
            // Fallback: load tenant from user relationship
            $tenant = $user->tenant;
        } else {
            // Superadmin cannot delete logo via this endpoint
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }
        
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

        if ($tenant->logo) {
            try {
                $this->wasabi->deleteFile($tenant->logo, $tenant);
                $tenant->update(['logo' => null]);
                
                Log::info('Logo deleted successfully', [
                    'tenant_id' => $tenant->id,
                ]);

                return response()->json([
                    'message' => 'Logo eliminado exitosamente'
                ]);
            } catch (\Exception $e) {
                Log::error('Error deleting logo', [
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'message' => 'Error al eliminar el logo: ' . $e->getMessage()
                ], 500);
            }
        }

        return response()->json([
            'message' => 'No hay logo para eliminar'
        ], 404);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\SocialMediaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SocialMediaSettingsController extends Controller
{
    protected SocialMediaSyncService $syncService;

    public function __construct(SocialMediaSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Get social media configuration
     */
    public function show(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        return response()->json([
            'data' => [
                'twitter' => [
                    'enabled' => $tenant->twitter_enabled,
                    'configured' => !empty($tenant->twitter_bearer_token) && !empty($tenant->twitter_user_id),
                    'username' => $tenant->twitter_username,
                    'user_id' => $tenant->twitter_user_id,
                ],
                'facebook' => [
                    'enabled' => $tenant->facebook_enabled,
                    'configured' => !empty($tenant->facebook_access_token) && !empty($tenant->facebook_page_id),
                    'page_id' => $tenant->facebook_page_id,
                ],
                'instagram' => [
                    'enabled' => $tenant->instagram_enabled,
                    'configured' => !empty($tenant->instagram_access_token) && !empty($tenant->instagram_user_id),
                    'username' => $tenant->instagram_username,
                    'user_id' => $tenant->instagram_user_id,
                ],
                'youtube' => [
                    'enabled' => $tenant->youtube_enabled,
                    'configured' => !empty($tenant->youtube_api_key) && !empty($tenant->youtube_channel_id),
                    'channel_id' => $tenant->youtube_channel_id,
                ],
                'auto_sync' => [
                    'enabled' => $tenant->social_auto_sync_enabled,
                    'interval_minutes' => $tenant->social_sync_interval_minutes,
                    'last_synced_at' => $tenant->social_last_synced_at?->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Update Twitter configuration
     */
    public function updateTwitter(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'bearer_token' => 'required_if:enabled,true|nullable|string',
            'user_id' => 'required_if:enabled,true|nullable|string',
            'username' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update([
            'twitter_enabled' => $request->enabled,
            'twitter_bearer_token' => $request->bearer_token,
            'twitter_user_id' => $request->user_id,
            'twitter_username' => $request->username,
        ]);

        return response()->json([
            'message' => 'Configuración de Twitter actualizada exitosamente',
        ]);
    }

    /**
     * Update Facebook configuration
     */
    public function updateFacebook(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'access_token' => 'required_if:enabled,true|nullable|string',
            'page_id' => 'required_if:enabled,true|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update([
            'facebook_enabled' => $request->enabled,
            'facebook_access_token' => $request->access_token,
            'facebook_page_id' => $request->page_id,
        ]);

        return response()->json([
            'message' => 'Configuración de Facebook actualizada exitosamente',
        ]);
    }

    /**
     * Update Instagram configuration
     */
    public function updateInstagram(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'access_token' => 'required_if:enabled,true|nullable|string',
            'user_id' => 'required_if:enabled,true|nullable|string',
            'username' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update([
            'instagram_enabled' => $request->enabled,
            'instagram_access_token' => $request->access_token,
            'instagram_user_id' => $request->user_id,
            'instagram_username' => $request->username,
        ]);

        return response()->json([
            'message' => 'Configuración de Instagram actualizada exitosamente',
        ]);
    }

    /**
     * Update YouTube configuration
     */
    public function updateYouTube(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'api_key' => 'required_if:enabled,true|nullable|string',
            'channel_id' => 'required_if:enabled,true|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update([
            'youtube_enabled' => $request->enabled,
            'youtube_api_key' => $request->api_key,
            'youtube_channel_id' => $request->channel_id,
        ]);

        return response()->json([
            'message' => 'Configuración de YouTube actualizada exitosamente',
        ]);
    }

    /**
     * Update auto-sync configuration
     */
    public function updateAutoSync(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'interval_minutes' => 'required|integer|min:5|max:1440',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant->update([
            'social_auto_sync_enabled' => $request->enabled,
            'social_sync_interval_minutes' => $request->interval_minutes,
        ]);

        return response()->json([
            'message' => 'Configuración de sincronización automática actualizada exitosamente',
        ]);
    }

    /**
     * Sync all networks
     */
    public function syncAll(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $results = $this->syncService->syncAll($tenant);

        $totalSynced = 0;
        $allErrors = [];

        foreach ($results as $platform => $result) {
            if ($result) {
                $totalSynced += $result['synced'];
                if (!empty($result['errors'])) {
                    $allErrors[$platform] = $result['errors'];
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Sincronización completada',
            'results' => $results,
            'total_synced' => $totalSynced,
            'errors' => $allErrors,
        ]);
    }

    /**
     * Sync specific platform
     */
    public function syncPlatform(Request $request, string $platform): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = Tenant::find($user->tenant_id);

        $result = match ($platform) {
            'twitter' => $this->syncService->syncTwitter($tenant),
            'facebook' => $this->syncService->syncFacebook($tenant),
            'instagram' => $this->syncService->syncInstagram($tenant),
            'youtube' => $this->syncService->syncYouTube($tenant),
            default => ['synced' => 0, 'errors' => ['Plataforma no válida']],
        };

        return response()->json([
            'success' => empty($result['errors']),
            'message' => "Posts de {$platform} sincronizados",
            'platform' => $platform,
            'synced' => $result['synced'],
            'errors' => $result['errors'],
        ]);
    }
}

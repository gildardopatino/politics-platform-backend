<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\SocialMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSocialMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId
    ) {}

    public function handle(SocialMediaSyncService $syncService): void
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::error("Tenant {$this->tenantId} not found for social media sync");
            return;
        }

        if (!$tenant->social_auto_sync_enabled) {
            Log::info("Auto-sync disabled for tenant {$tenant->slug}");
            return;
        }

        Log::info("Starting social media sync for tenant {$tenant->slug}");

        $results = $syncService->syncAll($tenant);

        $totalSynced = 0;
        foreach ($results as $platform => $result) {
            if ($result) {
                $totalSynced += $result['synced'];
                if (!empty($result['errors'])) {
                    Log::warning("Errors syncing {$platform} for {$tenant->slug}", $result['errors']);
                }
            }
        }

        Log::info("Social media sync completed for tenant {$tenant->slug}: {$totalSynced} posts synced");
    }
}

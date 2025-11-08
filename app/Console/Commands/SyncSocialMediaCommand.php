<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\SocialMediaSyncService;
use Illuminate\Console\Command;

class SyncSocialMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'social:sync 
                            {--tenant= : Tenant slug to sync (omit for all tenants)}
                            {--platform= : Platform to sync (twitter, facebook, instagram, youtube)}';

    /**
     * The console command description.
     */
    protected $description = 'Sync social media posts for one or all tenants';

    public function __construct(
        protected SocialMediaSyncService $syncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSlug = $this->option('tenant');
        $platform = $this->option('platform');

        if ($tenantSlug) {
            return $this->syncTenant($tenantSlug, $platform);
        }

        // Sync all tenants with auto-sync enabled
        $tenants = Tenant::where('social_auto_sync_enabled', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants with auto-sync enabled');
            return self::SUCCESS;
        }

        $this->info("Syncing {$tenants->count()} tenant(s)...");

        foreach ($tenants as $tenant) {
            $this->syncTenant($tenant->slug, $platform);
        }

        return self::SUCCESS;
    }

    protected function syncTenant(string $slug, ?string $platform): int
    {
        $tenant = Tenant::where('slug', $slug)->first();

        if (!$tenant) {
            $this->error("Tenant '{$slug}' not found");
            return self::FAILURE;
        }

        $this->info("Syncing tenant: {$tenant->name} ({$tenant->slug})");

        if ($platform) {
            $result = match ($platform) {
                'twitter' => $this->syncService->syncTwitter($tenant),
                'facebook' => $this->syncService->syncFacebook($tenant),
                'instagram' => $this->syncService->syncInstagram($tenant),
                'youtube' => $this->syncService->syncYouTube($tenant),
                default => null,
            };

            if ($result === null) {
                $this->error("Invalid platform: {$platform}");
                return self::FAILURE;
            }

            $this->displayResult($platform, $result);
        } else {
            $results = $this->syncService->syncAll($tenant);

            foreach ($results as $platformName => $result) {
                if ($result) {
                    $this->displayResult($platformName, $result);
                }
            }
        }

        return self::SUCCESS;
    }

    protected function displayResult(string $platform, array $result): void
    {
        if (empty($result['errors'])) {
            $this->info("  ✓ {$platform}: {$result['synced']} posts synced");
        } else {
            $this->warn("  ⚠ {$platform}: {$result['synced']} posts synced with errors");
            foreach ($result['errors'] as $error) {
                $this->line("    - {$error}");
            }
        }
    }
}


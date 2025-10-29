<?php

namespace App\Jobs\Campaigns;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Campaign $campaign
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CampaignService $campaignService): void
    {
        if ($this->campaign->status !== 'pending') {
            return;
        }

        $this->campaign->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $batchSize = config('campaign.batch_size', 100);
        $sentCount = 0;
        $failedCount = 0;

        $this->campaign->recipients()
            ->where('status', 'pending')
            ->chunk($batchSize, function ($recipients) use ($campaignService, &$sentCount, &$failedCount) {
                foreach ($recipients as $recipient) {
                    $success = $campaignService->sendToRecipient($recipient);
                    
                    if ($success) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                    }

                    $this->campaign->update([
                        'sent_count' => $sentCount,
                        'failed_count' => $failedCount,
                    ]);
                }

                // Rate limiting: wait 1 second between batches
                sleep(1);
            });

        $this->campaign->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->campaign->update([
            'status' => 'failed',
        ]);
    }
}

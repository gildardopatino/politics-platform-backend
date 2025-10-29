<?php

namespace App\Jobs\Meetings;

use App\Models\Meeting;
use App\Services\QRCodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateQRCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Meeting $meeting
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(QRCodeService $qrCodeService): void
    {
        if ($this->meeting->qr_code) {
            return; // QR code already generated
        }

        $qrCode = $qrCodeService->generateForMeeting(
            $this->meeting->id,
            $this->meeting->tenant->slug
        );

        $this->meeting->update(['qr_code' => $qrCode]);
    }
}

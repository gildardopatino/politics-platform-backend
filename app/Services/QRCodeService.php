<?php

namespace App\Services;

use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Str;

class QRCodeService
{
    public function generateForMeeting(int $meetingId, string $tenantSlug): string
    {
        $uniqueCode = Str::random(32);
        $url = config('app.url') . "/api/v1/meetings/check-in/{$uniqueCode}";
        
        $qrCodePath = "qr-codes/{$tenantSlug}";
        $fileName = "meeting-{$meetingId}-{$uniqueCode}.svg";
        
        if (!file_exists(storage_path("app/public/{$qrCodePath}"))) {
            mkdir(storage_path("app/public/{$qrCodePath}"), 0755, true);
        }
        
        QrCode::format('svg')
            ->size(300)
            ->generate($url, storage_path("app/public/{$qrCodePath}/{$fileName}"));
        
        return $uniqueCode;
    }

    public function getQRCodePath(string $qrCode, string $tenantSlug): ?string
    {
        $pattern = storage_path("app/public/qr-codes/{$tenantSlug}/meeting-*-{$qrCode}.svg");
        $files = glob($pattern);
        
        return $files ? str_replace(storage_path('app/public/'), '', $files[0]) : null;
    }
}

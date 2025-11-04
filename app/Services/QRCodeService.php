<?php

namespace App\Services;

use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Str;

class QRCodeService
{
    /**
     * Genera un código QR para una reunión y lo retorna en base64
     */
    public function generateForMeeting(int $meetingId, string $tenantSlug): array
    {
        $uniqueCode = Str::random(32);
        // URL directa al frontend
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $url = "{$frontendUrl}/meetings/check-in/{$uniqueCode}";
        
        // Generar QR en SVG (funciona sin imagick)
        $svgQR = QrCode::format('svg')
            ->size(300)
            ->errorCorrection('H')
            ->generate($url);
        
        // Guardar archivo físico como respaldo
        $qrCodePath = "qr-codes/{$tenantSlug}";
        $fileName = "meeting-{$meetingId}-{$uniqueCode}.svg";
        
        if (!file_exists(storage_path("app/public/{$qrCodePath}"))) {
            mkdir(storage_path("app/public/{$qrCodePath}"), 0755, true);
        }
        
        file_put_contents(
            storage_path("app/public/{$qrCodePath}/{$fileName}"),
            $svgQR
        );
        
        // Retornar código único y QR en base64
        return [
            'code' => $uniqueCode,
            'svg' => $svgQR,
            'svg_base64' => base64_encode($svgQR),
            'url' => $url,
            'file_path' => "storage/{$qrCodePath}/{$fileName}",
        ];
    }

    /**
     * Obtiene solo el base64 de un QR existente
     */
    public function getQRCodeBase64(string $qrCode): ?array
    {
        // URL directa al frontend
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $url = "{$frontendUrl}/meetings/check-in/{$qrCode}";
        
        $svgQR = QrCode::format('svg')
            ->size(300)
            ->errorCorrection('H')
            ->generate($url);
        
        return [
            'svg' => $svgQR,
            'svg_base64' => base64_encode($svgQR),
            'url' => $url,
        ];
    }

    public function getQRCodePath(string $qrCode, string $tenantSlug): ?string
    {
        $pattern = storage_path("app/public/qr-codes/{$tenantSlug}/meeting-*-{$qrCode}.svg");
        $files = glob($pattern);
        
        return $files ? str_replace(storage_path('app/public/'), '', $files[0]) : null;
    }
}

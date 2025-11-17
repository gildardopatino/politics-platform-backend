<?php

/**
 * Test Script for Evolution API WhatsApp Media Integration
 * 
 * This script tests sending images, videos, and documents via Evolution API
 * 
 * Usage: php test-evolution-media.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\WhatsAppNotificationService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "===========================================\n";
echo "Evolution API WhatsApp Media Test\n";
echo "===========================================\n\n";

// Test Configuration
$testTenantId = 1;
$testPhone = '+573116677099';

echo "Configuration:\n";
echo "- Tenant ID: {$testTenantId}\n";
echo "- Test Phone: {$testPhone}\n\n";

$whatsappService = app(WhatsAppNotificationService::class);

// Ask for confirmation
echo "⚠️  This will send REAL WhatsApp media messages to {$testPhone}\n";
echo "Continue? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) !== 'y') {
    echo "Test cancelled.\n";
    exit(0);
}

echo "\n";

// Test 1: Send Image via URL
echo "[1] Testing image send via URL...\n";
$imageUrl = 'https://via.placeholder.com/600x400.png/0066cc/ffffff?text=Evolution+API+Test';
$imageCaption = "🖼️ Test Image\n\nThis is a test image sent via Evolution API\nTenant: {$testTenantId}\nTime: " . now()->format('Y-m-d H:i:s');

$success = $whatsappService->sendImage(
    $testPhone,
    $imageUrl,
    $testTenantId,
    $imageCaption,
    'test-image.png',
    'image/png'
);

if ($success) {
    echo "✓ Image sent successfully!\n\n";
} else {
    echo "❌ Failed to send image\n";
    echo "Check logs: tail -f storage/logs/laravel.log\n\n";
}

sleep(2);

// Test 2: Send Document (PDF example with URL)
echo "[2] Testing document send...\n";
$documentUrl = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';
$documentCaption = "📄 Test Document\n\nThis is a test PDF sent via Evolution API";

$success = $whatsappService->sendDocument(
    $testPhone,
    $documentUrl,
    $testTenantId,
    'test-document.pdf',
    $documentCaption,
    'application/pdf'
);

if ($success) {
    echo "✓ Document sent successfully!\n\n";
} else {
    echo "❌ Failed to send document\n";
    echo "Check logs: tail -f storage/logs/laravel.log\n\n";
}

sleep(2);

// Test 3: Send Base64 Image (small example)
echo "[3] Testing base64 image send...\n";

// Create a simple 1x1 red pixel PNG in base64
$base64Image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
$base64Caption = "🎨 Base64 Test Image\n\nThis is a 1x1 pixel red image encoded in base64";

echo "⚠️  Base64 images may not work with all Evolution API configurations\n";
echo "Send base64 test? (y/n): ";
$line = fgets($handle);
if (trim($line) === 'y') {
    $success = $whatsappService->sendImage(
        $testPhone,
        $base64Image,
        $testTenantId,
        $base64Caption,
        'base64-test.png',
        'image/png'
    );

    if ($success) {
        echo "✓ Base64 image sent successfully!\n\n";
    } else {
        echo "❌ Failed to send base64 image\n";
        echo "Note: Evolution API may require full data URI format or URLs only\n\n";
    }
}

fclose($handle);

// Show updated statistics
echo "\n[4] Updated Statistics:\n";
$stats = $whatsappService->getTenantStatistics($testTenantId);

echo "Tenant Statistics:\n";
echo "  - Total Sent Today: {$stats['total_sent_today']}\n";
echo "  - Total Remaining: {$stats['total_remaining']}\n\n";

foreach ($stats['instances'] as $instance) {
    if ($instance['is_active']) {
        echo "  • {$instance['name']}: {$instance['sent_today']}/{$instance['daily_limit']} ({$instance['usage_percent']}%)\n";
    }
}

echo "\n";
echo "===========================================\n";
echo "Media test completed! ✓\n";
echo "===========================================\n";
echo "\nNext steps:\n";
echo "1. Check that media messages were received on {$testPhone}\n";
echo "2. Monitor logs: tail -f storage/logs/laravel.log | grep 'media'\n";
echo "3. Verify images/documents are displayed correctly\n";
echo "\nUsage examples in your code:\n";
echo "// Send image\n";
echo "\$whatsappService->sendImage(\$phone, \$imageUrl, \$tenantId, 'Caption');\n\n";
echo "// Send video\n";
echo "\$whatsappService->sendVideo(\$phone, \$videoUrl, \$tenantId, 'Caption');\n\n";
echo "// Send document\n";
echo "\$whatsappService->sendDocument(\$phone, \$docUrl, \$tenantId, 'file.pdf', 'Caption');\n\n";
echo "// Send custom media\n";
echo "\$whatsappService->sendMedia(\$phone, 'image', \$url, \$tenantId, 'Caption', 'file.png', 'image/png');\n";
echo "\n";

<?php

/**
 * Test Script for Voting Place Image Generation
 * 
 * This script generates a voting place image with voter data
 * 
 * Usage: php test-voting-image.php
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\VotingPlaceImageService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "===========================================\n";
echo "Voting Place Image Generation Test\n";
echo "===========================================\n\n";

// Test data
$cedula = '14398737';
$votingData = [
    'departamento' => 'TOLIMA',
    'ciudad' => 'IBAGUE',
    'puesto' => 'IE JOSE CELESTINO MUTIS SEDE 2',
    'mesa' => '9',
];

echo "Test Configuration:\n";
echo "- C.C: {$cedula}\n";
echo "- Departamento: {$votingData['departamento']}\n";
echo "- Ciudad: {$votingData['ciudad']}\n";
echo "- Puesto: {$votingData['puesto']}\n";
echo "- Mesa: {$votingData['mesa']}\n\n";

// Check if template exists
$templatePath = public_path('images/boceto1.png');
if (!file_exists($templatePath)) {
    echo "❌ ERROR: Template image not found at {$templatePath}\n";
    echo "Please make sure boceto1.png is in the public/images directory\n";
    exit(1);
}
echo "✓ Template image found: {$templatePath}\n\n";

// Check if font exists (optional)
$fontPath = public_path('fonts/RobotoCondensed-VariableFont_wght.ttf');
if (!file_exists($fontPath)) {
    echo "⚠️  Custom font not found at {$fontPath}\n";
    echo "The system will use default GD font instead.\n";
    echo "For better results, you can:\n";
    echo "1. Create fonts/ directory in public/\n";
    echo "2. Download RobotoCondensed font from Google Fonts\n";
    echo "3. Place the TTF file in public/fonts/\n\n";
} else {
    echo "✓ Custom font found: {$fontPath}\n\n";
}

// Generate image
echo "[1] Generating voting place image...\n";

try {
    $imageService = app(VotingPlaceImageService::class);
    $imageUrl = $imageService->generateVotingPlaceImage($cedula, $votingData);
    
    echo "✓ Image generated successfully!\n";
    echo "URL: {$imageUrl}\n";
    
    // Get local path
    $localPath = str_replace(url(''), public_path(''), $imageUrl);
    echo "Local path: {$localPath}\n";
    
    if (file_exists($localPath)) {
        $fileSize = filesize($localPath);
        echo "File size: " . number_format($fileSize / 1024, 2) . " KB\n\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Failed to generate image\n";
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}

// Ask if user wants to send via WhatsApp
echo "[2] Send via WhatsApp?\n";
echo "Enter phone number (or press Enter to skip): ";
$handle = fopen("php://stdin", "r");
$phone = trim(fgets($handle));

if (!empty($phone)) {
    echo "Enter tenant ID (default: 1): ";
    $tenantIdInput = trim(fgets($handle));
    $tenantId = !empty($tenantIdInput) ? (int)$tenantIdInput : 1;
    
    echo "\nSending image via WhatsApp...\n";
    
    try {
        $success = $imageService->sendVotingPlaceImageWhatsApp(
            $phone,
            $cedula,
            $votingData,
            $tenantId
        );
        
        if ($success) {
            echo "✓ Image sent successfully via WhatsApp!\n";
        } else {
            echo "❌ Failed to send image via WhatsApp\n";
            echo "Check logs: tail -f storage/logs/laravel.log\n";
        }
    } catch (\Exception $e) {
        echo "❌ Exception: {$e->getMessage()}\n";
    }
} else {
    echo "Skipped WhatsApp sending.\n";
}

fclose($handle);

echo "\n===========================================\n";
echo "Test completed! ✓\n";
echo "===========================================\n";
echo "\nNext steps:\n";
echo "1. Check the generated image at: {$imageUrl}\n";
echo "2. Adjust text positions in VotingPlaceImageService.php if needed\n";
echo "3. The positions are:\n";
echo "   - C.C: x=150, y=330, size=28\n";
echo "   - Departamento: x=150, y=380, size=26\n";
echo "   - Ciudad: x=150, y=420, size=26\n";
echo "   - Puesto: x=150, y=460, size=22\n";
echo "   - Mesa: x=150, y=510, size=40\n";
echo "\n4. To adjust positions, edit:\n";
echo "   app/Services/VotingPlaceImageService.php\n";
echo "   Look for the generateVotingPlaceImage() method\n";
echo "\n";

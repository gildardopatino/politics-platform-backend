<?php

/**
 * Direct test script for voting place image with WhatsApp send
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\VotingPlaceImageService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "===========================================\n";
echo "Voting Place Image - Direct Test\n";
echo "===========================================\n\n";

// Test data
$cedula = '14398737';
$phone = '3116677099';
$tenantId = 1;

$votingData = [
    'departamento' => 'TOLIMA',
    'ciudad' => 'IBAGUE',
    'puesto' => 'IE JOSE CELESTINO MUTIS SEDE 2',
    'mesa' => '9',
    'direccion' => 'CARRERA 5 # 28-75',
];

echo "Configuration:\n";
echo "- C.C: {$cedula}\n";
echo "- Phone: {$phone}\n";
echo "- Tenant ID: {$tenantId}\n";
echo "- Departamento: {$votingData['departamento']}\n";
echo "- Ciudad: {$votingData['ciudad']}\n";
echo "- Puesto: {$votingData['puesto']}\n";
echo "- Mesa: {$votingData['mesa']}\n\n";

// Check template
$templatePath = public_path('images/boceto1.jpg');
if (!file_exists($templatePath)) {
    echo "❌ ERROR: Template not found at {$templatePath}\n";
    exit(1);
}
echo "✓ Template found\n\n";

try {
    $imageService = app(VotingPlaceImageService::class);
    
    echo "[1] Generating image...\n";
    $imageUrl = $imageService->generateVotingPlaceImage($cedula, $votingData);
    echo "✓ Image generated: {$imageUrl}\n\n";
    exit;
    echo "[2] Sending via WhatsApp to {$phone}...\n";
    $success = $imageService->sendVotingPlaceImageWhatsApp(
        $phone,
        $cedula,
        $votingData,
        $tenantId
    );
    
    
    if ($success) {
        echo "✓ Image sent successfully!\n";
        echo "\nCheck your WhatsApp at {$phone}\n";
    } else {
        echo "❌ Failed to send\n";
        echo "Check logs: tail -f storage/logs/laravel.log\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "Trace: {$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n===========================================\n";
echo "Test completed!\n";
echo "===========================================\n";

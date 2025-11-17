<?php

/**
 * Test Voting Place API Endpoints
 */

echo "===========================================\n";
echo "Testing Voting Place API Endpoints\n";
echo "===========================================\n\n";

// Test data
$baseUrl = 'http://localhost:8000/api/v1';
$testData = [
    'cedula' => '14398737',
    'departamento' => 'TOLIMA',
    'ciudad' => 'IBAGUE',
    'puesto' => 'IE JOSE CELESTINO MUTIS SEDE 2',
    'mesa' => '9'
];

echo "Test Data:\n";
foreach ($testData as $key => $value) {
    echo "  - {$key}: {$value}\n";
}
echo "\n";

// Test 1: Generate Image
echo "[1] Testing POST /api/v1/voting-place/generate-image\n";
echo "    (Generate image without sending WhatsApp)\n\n";

$ch = curl_init("{$baseUrl}/voting-place/generate-image");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
if ($response) {
    $data = json_decode($response, true);
    echo "Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n\n";
    
    if (isset($data['data']['image_url'])) {
        echo "✓ Image URL: {$data['data']['image_url']}\n";
    }
} else {
    echo "❌ No response\n";
}
echo "\n";

// Test 2: Send via WhatsApp
echo "[2] Testing POST /api/v1/voting-place/send-whatsapp\n";
echo "    (Generate and send image via WhatsApp)\n\n";

$testDataWhatsApp = array_merge($testData, [
    'phone' => '3116677099',
    'tenant_id' => 1
]);

$ch = curl_init("{$baseUrl}/voting-place/send-whatsapp");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testDataWhatsApp));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
if ($response) {
    $data = json_decode($response, true);
    echo "Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n\n";
    
    if (isset($data['success']) && $data['success']) {
        echo "✓ Image sent to WhatsApp successfully!\n";
    }
} else {
    echo "❌ No response\n";
}

echo "\n===========================================\n";
echo "API Endpoints Documentation\n";
echo "===========================================\n\n";

echo "1. Generate Image Only:\n";
echo "   POST /api/v1/voting-place/generate-image\n";
echo "   Body (JSON):\n";
echo "   {\n";
echo "     \"cedula\": \"14398737\",\n";
echo "     \"departamento\": \"TOLIMA\",\n";
echo "     \"ciudad\": \"IBAGUE\",\n";
echo "     \"puesto\": \"IE JOSE CELESTINO MUTIS SEDE 2\",\n";
echo "     \"mesa\": \"9\"\n";
echo "   }\n\n";

echo "2. Generate and Send via WhatsApp:\n";
echo "   POST /api/v1/voting-place/send-whatsapp\n";
echo "   Body (JSON):\n";
echo "   {\n";
echo "     \"cedula\": \"14398737\",\n";
echo "     \"departamento\": \"TOLIMA\",\n";
echo "     \"ciudad\": \"IBAGUE\",\n";
echo "     \"puesto\": \"IE JOSE CELESTINO MUTIS SEDE 2\",\n";
echo "     \"mesa\": \"9\",\n";
echo "     \"phone\": \"3116677099\",\n";
echo "     \"tenant_id\": 1\n";
echo "   }\n\n";

echo "Note: Both endpoints are PUBLIC (no authentication required)\n";
echo "\n";

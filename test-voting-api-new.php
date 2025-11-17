<?php
/**
 * Test script for Voting Place API
 * Tests image generation and WhatsApp sending via public API endpoints
 * 
 * Updated: 2025-11-17
 * Now only requires cedula - voting data is fetched from database
 */

require_once __DIR__ . '/vendor/autoload.php';

// Configuration
$baseUrl = 'http://localhost:8000/api/v1';
$testCedula = '14398737'; // Test cedula (must exist in voters table)
$testPhone = '3116677099'; // Optional - will use voter's phone if not provided
$tenantId = 1;

echo "===========================================\n";
echo "VOTING PLACE API TESTS (Database Lookup)\n";
echo "===========================================\n\n";

// Test 1: Generate Image (only requires cedula)
echo "TEST 1: Generate Voting Place Image\n";
echo "-----------------------------------\n";

$data = [
    'cedula' => $testCedula,
];

echo "Request to: {$baseUrl}/voting-place/generate-image\n";
echo "Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/voting-place/generate-image");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

if ($httpCode === 201) {
    $responseData = json_decode($response, true);
    echo "✓ Image generated successfully!\n";
    echo "Image URL: {$responseData['data']['image_url']}\n";
    echo "Voter Name: {$responseData['data']['nombres']} {$responseData['data']['apellidos']}\n";
    echo "Voting Place: {$responseData['data']['voting_data']['puesto']}\n\n";
} else {
    echo "✗ Failed to generate image\n\n";
}

// Test 2: Generate and Send via WhatsApp
echo "TEST 2: Generate and Send via WhatsApp\n";
echo "--------------------------------------\n";

$whatsappData = [
    'cedula' => $testCedula,
    'phone' => $testPhone, // Optional
    'tenant_id' => $tenantId,
];

echo "Request to: {$baseUrl}/voting-place/send-whatsapp\n";
echo "Data: " . json_encode($whatsappData, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/voting-place/send-whatsapp");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($whatsappData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

if ($httpCode === 200) {
    $responseData = json_decode($response, true);
    echo "✓ WhatsApp message sent successfully!\n";
    echo "Sent to: {$responseData['data']['phone']}\n";
    echo "Voter: {$responseData['data']['nombres']} {$responseData['data']['apellidos']}\n\n";
} else {
    echo "✗ Failed to send WhatsApp message\n\n";
}

// Test 3: Test Error Cases
echo "TEST 3: Error Handling\n";
echo "---------------------\n";

// Test with non-existent cedula
echo "3a. Testing with non-existent cedula...\n";
$errorData = ['cedula' => '99999999'];

$ch = curl_init("{$baseUrl}/voting-place/generate-image");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($errorData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

if ($httpCode === 404) {
    echo "✓ Correctly returns 404 for non-existent voter\n\n";
} else {
    echo "✗ Unexpected response code\n\n";
}

// Test without phone when voter has no phone
echo "3b. Testing WhatsApp without phone parameter...\n";
$noPhoneData = [
    'cedula' => $testCedula,
    // No phone parameter - will use voter's phone
    'tenant_id' => $tenantId,
];

$ch = curl_init("{$baseUrl}/voting-place/send-whatsapp");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($noPhoneData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response Code: {$httpCode}\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";

if ($httpCode === 200) {
    echo "✓ Successfully used voter's registered phone\n\n";
} elseif ($httpCode === 422) {
    echo "✓ Correctly returns 422 when voter has no phone\n\n";
} else {
    echo "✗ Unexpected response code\n\n";
}

echo "===========================================\n";
echo "TESTS COMPLETED\n";
echo "===========================================\n";

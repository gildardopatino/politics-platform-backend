<?php

require __DIR__ . '/vendor/autoload.php';

use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configure MercadoPago
$accessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'];
echo "Access Token: " . substr($accessToken, 0, 20) . "...\n\n";

MercadoPagoConfig::setAccessToken($accessToken);

try {
    $client = new PreferenceClient();
    
    echo "Creating test preference...\n";
    
    $preference = $client->create([
        'items' => [
            [
                'title' => 'Test Product',
                'quantity' => 1,
                'unit_price' => 1000.00,
                'currency_id' => 'COP',
            ]
        ],
        'payer' => [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ],
        'back_urls' => [
            'success' => 'http://localhost:3000/success',
            'failure' => 'http://localhost:3000/failure',
            'pending' => 'http://localhost:3000/pending',
        ],
        'external_reference' => 'test-123',
    ]);
    
    echo "\n✅ Success!\n";
    echo "Preference ID: " . $preference->id . "\n";
    echo "Init Point: " . $preference->init_point . "\n";
    echo "Sandbox Init Point: " . $preference->sandbox_init_point . "\n";
    
} catch (\MercadoPago\Exceptions\MPApiException $e) {
    echo "\n❌ MercadoPago API Error:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Status Code: " . $e->getStatusCode() . "\n";
    
    $response = $e->getApiResponse();
    if ($response) {
        echo "Response Content:\n";
        print_r($response->getContent());
    }
    
} catch (\Exception $e) {
    echo "\n❌ General Error:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

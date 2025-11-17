<?php

/**
 * Test Script for Evolution API WhatsApp Integration
 * 
 * This script tests the new Evolution API integration with load balancing
 * 
 * Usage: php test-evolution-api.php
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\TenantWhatsAppInstance;
use App\Services\WhatsAppNotificationService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "===========================================\n";
echo "Evolution API WhatsApp Integration Test\n";
echo "===========================================\n\n";

// Test Configuration
$testTenantId = 1; // Change to your test tenant ID
$testPhone = '+573116677099'; // Change to your test phone number

echo "Configuration:\n";
echo "- Tenant ID: {$testTenantId}\n";
echo "- Test Phone: {$testPhone}\n\n";

// 1. Check if tenant has instances
echo "[1] Checking WhatsApp instances for tenant...\n";
$instances = TenantWhatsAppInstance::where('tenant_id', $testTenantId)->get();

if ($instances->isEmpty()) {
    echo "❌ ERROR: No WhatsApp instances found for tenant {$testTenantId}\n";
    echo "\nPlease create an instance first:\n";
    echo "POST /api/v1/tenants/{$testTenantId}/whatsapp-instances\n";
    echo "{\n";
    echo "  \"phone_number\": \"+573116677099\",\n";
    echo "  \"instance_name\": \"test-instance\",\n";
    echo "  \"evolution_api_key\": \"YOUR_API_KEY\",\n";
    echo "  \"evolution_api_url\": \"https://your-evolution-api.com\",\n";
    echo "  \"daily_message_limit\": 1000,\n";
    echo "  \"is_active\": true\n";
    echo "}\n";
    exit(1);
}

echo "✓ Found {$instances->count()} instance(s)\n\n";

// Display instances
echo "Instances:\n";
foreach ($instances as $index => $instance) {
    echo "  [{$index}] {$instance->instance_name}\n";
    echo "      - Phone: {$instance->phone_number}\n";
    echo "      - Active: " . ($instance->is_active ? '✓' : '✗') . "\n";
    echo "      - Daily Limit: {$instance->daily_message_limit}\n";
    echo "      - Sent Today: {$instance->messages_sent_today}\n";
    echo "      - Remaining: {$instance->getRemainingQuota()}\n";
    echo "      - Can Send: " . ($instance->canSendMessage() ? '✓' : '✗') . "\n";
    echo "      - API URL: {$instance->getEvolutionApiBaseUrl()}\n";
    echo "\n";
}

// 2. Check available instances
echo "[2] Checking available instances (active + has quota)...\n";
$available = TenantWhatsAppInstance::where('tenant_id', $testTenantId)
    ->active()
    ->withAvailableQuota()
    ->orderBy('messages_sent_today', 'asc')
    ->get();

if ($available->isEmpty()) {
    echo "❌ ERROR: No available instances (all inactive or quota exhausted)\n";
    echo "\nTroubleshooting:\n";
    echo "- Check if at least one instance has is_active = true\n";
    echo "- Check if at least one instance has messages_sent_today < daily_message_limit\n";
    echo "- Use POST /api/v1/tenants/{$testTenantId}/whatsapp-instances/{id}/reset-counter to reset\n";
    exit(1);
}

echo "✓ Found {$available->count()} available instance(s)\n";
echo "  Selected for next message: {$available->first()->instance_name}\n";
echo "  (Least used: {$available->first()->messages_sent_today} messages today)\n\n";

// 3. Test WhatsApp service
echo "[3] Testing WhatsApp notification service...\n";

$whatsappService = app(WhatsAppNotificationService::class);

// Ask for confirmation
echo "⚠️  This will send a REAL WhatsApp message to {$testPhone}\n";
echo "Continue? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) !== 'y') {
    echo "Test cancelled.\n";
    exit(0);
}

echo "Sending test message...\n";
$message = "🧪 Test message from Evolution API\n\n";
$message .= "Tenant ID: {$testTenantId}\n";
$message .= "Time: " . now()->format('Y-m-d H:i:s') . "\n";
$message .= "This is a test of the new Evolution API integration with load balancing.";

$success = $whatsappService->sendMessage($testPhone, $message, $testTenantId);

if ($success) {
    echo "✓ Message sent successfully!\n\n";
} else {
    echo "❌ Failed to send message\n";
    echo "Check logs: tail -f storage/logs/laravel.log\n\n";
    exit(1);
}

// 4. Verify counter increment
echo "[4] Verifying message counter increment...\n";
$instanceAfter = TenantWhatsAppInstance::where('tenant_id', $testTenantId)
    ->active()
    ->withAvailableQuota()
    ->orderBy('messages_sent_today', 'asc')
    ->first();

echo "Instance: {$instanceAfter->instance_name}\n";
echo "Messages sent today: {$instanceAfter->messages_sent_today}\n";
echo "Remaining quota: {$instanceAfter->getRemainingQuota()}\n\n";

// 5. Test statistics
echo "[5] Testing tenant statistics...\n";
$stats = $whatsappService->getTenantStatistics($testTenantId);

echo "Tenant Statistics:\n";
echo "  - Total Instances: {$stats['total_instances']}\n";
echo "  - Active Instances: {$stats['active_instances']}\n";
echo "  - Total Daily Limit: {$stats['total_daily_limit']}\n";
echo "  - Total Sent Today: {$stats['total_sent_today']}\n";
echo "  - Total Remaining: {$stats['total_remaining']}\n\n";

echo "Per-Instance Breakdown:\n";
foreach ($stats['instances'] as $instance) {
    echo "  • {$instance['name']} ({$instance['phone']})\n";
    echo "    Sent: {$instance['sent_today']}/{$instance['daily_limit']} ({$instance['usage_percent']}%)\n";
    echo "    Status: " . ($instance['is_active'] ? 'Active' : 'Inactive') . "\n";
}

echo "\n";

// 6. Test load balancing (if multiple instances)
if ($instances->count() > 1 && $available->count() > 1) {
    echo "[6] Testing load balancing with multiple instances...\n";
    echo "⚠️  This will send 5 test messages\n";
    echo "Continue? (y/n): ";
    $line = fgets($handle);
    if (trim($line) === 'y') {
        echo "Sending 5 messages to test load balancing...\n";
        
        $results = [];
        for ($i = 1; $i <= 5; $i++) {
            $msg = "Test message #{$i} for load balancing";
            $success = $whatsappService->sendMessage($testPhone, $msg, $testTenantId);
            $results[] = $success ? '✓' : '✗';
            echo "  Message {$i}: " . ($success ? '✓' : '✗') . "\n";
            sleep(1); // Wait 1 second between messages
        }
        
        echo "\nResults: " . implode(' ', $results) . "\n";
        
        // Show updated statistics
        echo "\nUpdated statistics:\n";
        $statsAfter = $whatsappService->getTenantStatistics($testTenantId);
        foreach ($statsAfter['instances'] as $instance) {
            if ($instance['is_active']) {
                echo "  • {$instance['name']}: {$instance['sent_today']} messages\n";
            }
        }
        echo "\n";
    }
}

fclose($handle);

// Summary
echo "===========================================\n";
echo "Test completed successfully! ✓\n";
echo "===========================================\n";
echo "\nNext steps:\n";
echo "1. Check that messages were received on {$testPhone}\n";
echo "2. Monitor logs: tail -f storage/logs/laravel.log\n";
echo "3. Check instance statistics via API:\n";
echo "   GET /api/v1/tenants/{$testTenantId}/whatsapp-instances/{id}/statistics\n";
echo "\n";

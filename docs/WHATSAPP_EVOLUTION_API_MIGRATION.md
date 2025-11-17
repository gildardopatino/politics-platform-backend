# WhatsApp Evolution API - Migration Guide

## Overview

The WhatsApp notification system has been **migrated from N8N to Evolution API** direct integration. The system now uses **multiple WhatsApp instances per tenant** with **intelligent load balancing** and **daily quota management**.

---

## Key Changes

### Before (N8N)
```php
// Single webhook URL for all tenants
$whatsappService->sendMessage(
    $phone, 
    $message, 
    $userToken  // Bearer token authentication
);
```

### After (Evolution API)
```php
// Multiple instances per tenant with load balancing
$whatsappService->sendMessage(
    $phone, 
    $message, 
    $tenantId  // Uses tenant's Evolution API instances
);
```

---

## Architecture

### Database Schema
```sql
tenant_whatsapp_instances
├── id
├── tenant_id                  // Foreign key to tenants
├── phone_number              // E.164 format (e.g., +573116677099)
├── instance_name             // Evolution API instance name
├── evolution_api_key         // ApiKey for authentication
├── evolution_api_url         // Base URL (e.g., https://evo.example.com)
├── daily_message_limit       // Max messages per day (1-100000)
├── messages_sent_today       // Current day counter
├── last_reset_date           // Date of last counter reset
├── is_active                 // Instance status
└── notes                     // Optional notes
```

### Load Balancing Strategy

The system uses a **hybrid strategy**:

1. **Primary**: Least-used algorithm (messages_sent_today ASC)
2. **Secondary**: Round-robin with cache per tenant

```php
// Get available instances sorted by usage
$instances = TenantWhatsAppInstance::where('tenant_id', $tenantId)
    ->active()
    ->withAvailableQuota()
    ->orderBy('messages_sent_today', 'asc')
    ->get();

// Round-robin selection from available instances
$cacheKey = "whatsapp_instance_index_{$tenantId}";
$currentIndex = Cache::get($cacheKey, 0);
$instance = $instances->get($currentIndex % $instances->count());
Cache::put($cacheKey, ($currentIndex + 1) % $instances->count(), now()->addMinutes(60));
```

**Benefits**:
- Distributes load evenly across instances
- Prevents quota exhaustion on single instance
- Automatic failover to instances with available quota
- No manual intervention required

---

## Service API

### WhatsAppNotificationService

#### sendMessage()
```php
/**
 * Send WhatsApp message using tenant's Evolution API instances
 * 
 * @param string $phone Phone number (E.164 or 10 digits Colombian)
 * @param string $message Message text
 * @param int $tenantId Tenant ID
 * @return bool Success status
 */
public function sendMessage(string $phone, string $message, int $tenantId): bool
```

**Evolution API Request**:
```http
POST {{evolution_api_url}}/message/sendText/{{instance_name}}
Headers:
  apikey: {{evolution_api_key}}
  Content-Type: application/json
Body:
{
  "number": "573116677099",
  "text": "Your message here"
}
```

**Phone Normalization**:
```php
// Input formats accepted:
"+57 311 667 7099"  → "573116677099"
"57-311-667-7099"   → "573116677099"
"3116677099"        → "573116677099" (adds 57 prefix)
"573116677099"      → "573116677099" (already normalized)
```

#### getTenantStatistics()
```php
/**
 * Get usage statistics for all tenant's instances
 * 
 * @param int $tenantId
 * @return array
 */
public function getTenantStatistics(int $tenantId): array
```

**Response Example**:
```json
{
  "total_instances": 3,
  "active_instances": 2,
  "total_daily_limit": 3000,
  "total_sent_today": 450,
  "total_remaining": 2550,
  "instances": [
    {
      "id": 1,
      "name": "whatsapp-primary",
      "phone": "+573116677099",
      "is_active": true,
      "daily_limit": 1000,
      "sent_today": 230,
      "remaining": 770,
      "usage_percent": 23.0
    },
    {
      "id": 2,
      "name": "whatsapp-secondary",
      "phone": "+573117788099",
      "is_active": true,
      "daily_limit": 1000,
      "sent_today": 220,
      "remaining": 780,
      "usage_percent": 22.0
    },
    {
      "id": 3,
      "name": "whatsapp-backup",
      "phone": "+573118899099",
      "is_active": false,
      "daily_limit": 1000,
      "sent_today": 0,
      "remaining": 1000,
      "usage_percent": 0.0
    }
  ]
}
```

---

## Migration Steps Completed

### ✅ 1. Service Refactoring
- Changed method signature: `sendMessage($phone, $message, $tenantId)`
- Removed N8N webhook URL dependency
- Removed Bearer token authentication
- Added Evolution API integration
- Implemented instance selection algorithm
- Added load balancing logic
- Added usage tracking

### ✅ 2. Updated All Callers

#### MeetingController
```php
// Before
$whatsappService->sendMessage(
    $meeting->planner->phone,
    $message,
    config('services.n8n.auth_token')
);

// After
$whatsappService->sendMessage(
    $meeting->planner->phone,
    $message,
    $meeting->tenant_id
);
```

#### ResourceAllocationController
```php
// Before
$whatsappService->sendMessage(
    $meeting->planner->phone,
    $message,
    config('services.n8n.auth_token')
);

// After
$whatsappService->sendMessage(
    $meeting->planner->phone,
    $message,
    $meeting->tenant_id
);
```

#### SendCommitmentReminderJob
```php
// Before
$whatsappService->sendMessage(
    $this->commitment->assignedUser->phone,
    $message,
    config('services.n8n.auth_token')
);

// After
$whatsappService->sendMessage(
    $this->commitment->assignedUser->phone,
    $message,
    $this->commitment->tenant_id
);
```

#### CampaignService
```php
// Before
$token = $campaign->creator_token;
$whatsappService->sendMessage(
    $recipient->recipient_value,
    $campaign->message,
    $token
);

// After
$whatsappService->sendMessage(
    $recipient->recipient_value,
    $campaign->message,
    $campaign->tenant_id
);
```

---

## Instance Management

### Creating Instances (Super Admin Only)

```http
POST /api/v1/tenants/{tenantId}/whatsapp-instances
Authorization: Bearer {{superadmin_token}}
Content-Type: application/json

{
  "phone_number": "+573116677099",
  "instance_name": "whatsapp-primary",
  "evolution_api_key": "B6D711FCDE4D...C8A882",
  "evolution_api_url": "https://evo.example.com",
  "daily_message_limit": 1000,
  "is_active": true,
  "notes": "Primary WhatsApp instance"
}
```

### Viewing Statistics

```http
GET /api/v1/tenants/{tenantId}/whatsapp-instances/{id}/statistics
Authorization: Bearer {{superadmin_token}}
```

**Response**:
```json
{
  "instance": {
    "id": 1,
    "name": "whatsapp-primary",
    "phone": "+573116677099"
  },
  "usage_today": {
    "sent": 450,
    "limit": 1000,
    "remaining": 550,
    "percentage": 45.0
  },
  "status": {
    "is_active": true,
    "can_send": true,
    "last_reset": "2025-06-15T00:00:00Z"
  }
}
```

### Manual Counter Reset

```http
POST /api/v1/tenants/{tenantId}/whatsapp-instances/{id}/reset-counter
Authorization: Bearer {{superadmin_token}}
```

---

## Quota Management

### Daily Limits
- Each instance has a `daily_message_limit` (default: 1000)
- Counter automatically resets at midnight (tenant's timezone)
- Manual reset available via API endpoint

### Auto-Reset Logic
```php
public function resetDailyCounterIfNeeded(): void
{
    $lastReset = $this->last_reset_date 
        ? Carbon::parse($this->last_reset_date) 
        : null;

    // Reset if never reset OR last reset was yesterday or earlier
    if (!$lastReset || $lastReset->isYesterday() || $lastReset->isPast()) {
        if (!$lastReset || !$lastReset->isToday()) {
            $this->messages_sent_today = 0;
            $this->last_reset_date = now();
            $this->save();
        }
    }
}
```

### Scopes for Instance Selection
```php
// Only active instances
TenantWhatsAppInstance::active();

// Only instances with available quota
TenantWhatsAppInstance::withAvailableQuota();

// Combined
TenantWhatsAppInstance::where('tenant_id', $tenantId)
    ->active()
    ->withAvailableQuota()
    ->orderBy('messages_sent_today', 'asc')
    ->get();
```

---

## Error Handling

### No Available Instances
```php
if (!$instance) {
    Log::error('No WhatsApp instances available for tenant', [
        'tenant_id' => $tenantId,
        'phone' => $normalizedPhone,
    ]);
    return false;
}
```

**Possible Causes**:
- No instances configured for tenant
- All instances are inactive (`is_active = false`)
- All instances reached daily limit
- All instances are deleted (soft-deleted)

**Solution**: Create new instances or increase daily limits

### Evolution API Errors
```php
if ($response->successful()) {
    $instance->incrementSentCount();
    return true;
}

Log::warning('Evolution API returned non-success status', [
    'tenant_id' => $tenantId,
    'instance_id' => $instance->id,
    'phone' => $normalizedPhone,
    'status' => $response->status(),
    'response' => $response->body(),
]);
```

**Common Evolution API Errors**:
- `401`: Invalid API key
- `404`: Instance not found
- `429`: Rate limit exceeded
- `500`: Evolution API internal error

---

## Testing

### Test Message Sending
```php
use App\Services\WhatsAppNotificationService;

$whatsappService = app(WhatsAppNotificationService::class);

// Test with tenant ID 1
$success = $whatsappService->sendMessage(
    phone: '+573116677099',
    message: 'Test message from Evolution API',
    tenantId: 1
);

if ($success) {
    echo "Message sent successfully!";
} else {
    echo "Failed to send message. Check logs.";
}
```

### Test Load Balancing
```php
// Send 10 messages and check distribution
for ($i = 1; $i <= 10; $i++) {
    $whatsappService->sendMessage(
        "+57311667709{$i}",
        "Test message #{$i}",
        1
    );
    sleep(1);
}

// Check statistics
$stats = $whatsappService->getTenantStatistics(1);
dd($stats['instances']); // Verify messages_sent_today is distributed
```

### Test Quota Exhaustion
```php
// Set instance limit to 5
$instance = TenantWhatsAppInstance::find(1);
$instance->update(['daily_message_limit' => 5]);

// Try to send 10 messages
for ($i = 1; $i <= 10; $i++) {
    $success = $whatsappService->sendMessage(
        "+573116677099",
        "Message #{$i}",
        1
    );
    echo $i . ": " . ($success ? "✓" : "✗") . "\n";
}

// Messages 6-10 should use other instances or fail if none available
```

---

## Monitoring

### Logs to Watch
```bash
# Successful sends
tail -f storage/logs/laravel.log | grep "WhatsApp message sent successfully"

# Failures
tail -f storage/logs/laravel.log | grep "Failed to send WhatsApp message"

# No available instances
tail -f storage/logs/laravel.log | grep "No WhatsApp instances available"
```

### Key Metrics
- `messages_sent_today` per instance
- `daily_message_limit` utilization percentage
- Instance availability (`is_active`)
- API response times
- Error rates per instance

---

## Best Practices

### 1. Multiple Instances per Tenant
```php
// Recommended: At least 2 active instances
// Example: 
//  - whatsapp-primary (limit: 1000)
//  - whatsapp-secondary (limit: 1000)
//  - whatsapp-backup (limit: 500, inactive)
```

### 2. Daily Limits
```php
// Set realistic limits based on Evolution API tier
// Conservative: 500-1000 per instance
// Moderate: 1000-5000 per instance
// Aggressive: 5000+ per instance (verify with provider)
```

### 3. Phone Number Format
```php
// Always use E.164 format in database
// Service handles normalization automatically
"+573116677099"  // ✓ Correct
"3116677099"     // ✓ Will be normalized
"311-667-7099"   // ✓ Will be normalized
```

### 4. Error Handling
```php
// Always check return value
$success = $whatsappService->sendMessage($phone, $message, $tenantId);

if (!$success) {
    // Implement fallback (SMS, email, etc.)
    // Or queue for retry
}
```

### 5. Monitoring
```php
// Check tenant statistics daily
$stats = $whatsappService->getTenantStatistics($tenantId);

if ($stats['total_remaining'] < 100) {
    // Alert: Low quota remaining
    // Consider adding more instances or increasing limits
}
```

---

## Troubleshooting

### Problem: No messages being sent
**Check**:
1. `tenant_whatsapp_instances` table has records for tenant
2. At least one instance is `is_active = true`
3. At least one instance has `messages_sent_today < daily_message_limit`
4. Evolution API URL is reachable
5. Evolution API key is valid

### Problem: Messages only using one instance
**Check**:
1. Multiple instances exist for tenant
2. All instances are active
3. Cache is working (check `whatsapp_instance_index_{tenantId}` key)
4. Instances have different `messages_sent_today` values

### Problem: Evolution API 401 error
**Check**:
1. `evolution_api_key` is correct in database
2. Key hasn't expired
3. Evolution API instance is running

### Problem: Daily limit reached too quickly
**Solution**:
1. Increase `daily_message_limit` for instance
2. Add more instances for the tenant
3. Implement message queuing to spread over time

---

## Configuration

### Environment Variables (Optional)
```env
# Default Evolution API settings (can be overridden per instance)
EVOLUTION_API_URL=https://evo.example.com
EVOLUTION_API_DEFAULT_LIMIT=1000

# Cache TTL for round-robin index
WHATSAPP_INSTANCE_CACHE_TTL=60
```

### Config Files
No specific config needed - all settings are per-instance in database.

---

## API Documentation

See complete API documentation:
- [WHATSAPP_INSTANCES_API.md](./WHATSAPP_INSTANCES_API.md)
- [WHATSAPP_INSTANCES_JSON_EXAMPLES.md](./WHATSAPP_INSTANCES_JSON_EXAMPLES.md)

---

## Migration Checklist

- [x] Create `tenant_whatsapp_instances` table
- [x] Create `TenantWhatsAppInstance` model with quota tracking
- [x] Create CRUD APIs for super admin
- [x] Refactor `WhatsAppNotificationService` for Evolution API
- [x] Update `MeetingController` to use `tenant_id`
- [x] Update `ResourceAllocationController` to use `tenant_id`
- [x] Update `SendCommitmentReminderJob` to use `tenant_id`
- [x] Update `CampaignService` to use `tenant_id`
- [x] Remove N8N webhook dependencies
- [x] Create documentation

---

## Summary

The migration from N8N to Evolution API provides:

✅ **Direct Integration**: No intermediate webhook service  
✅ **Multi-Instance Support**: Multiple WhatsApp numbers per tenant  
✅ **Load Balancing**: Intelligent distribution across instances  
✅ **Quota Management**: Daily limits with auto-reset  
✅ **Better Control**: Per-instance configuration and monitoring  
✅ **Higher Reliability**: Automatic failover to available instances  
✅ **Super Admin Control**: Centralized instance management  

**All existing code paths** (meetings, resources, commitments, campaigns) **now use Evolution API automatically** without breaking changes.

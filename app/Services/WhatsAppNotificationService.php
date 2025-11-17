<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantWhatsAppInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WhatsAppNotificationService
{
    /**
     * Send WhatsApp message using tenant's Evolution API instances
     * 
     * @param string $phone Phone number with country code (e.g., +573116677099)
     * @param string $message Message text
     * @param int $tenantId Tenant ID
     * @return bool
     */
    public function sendMessage(string $phone, string $message, int $tenantId): bool
    {
        // Normalize phone number (remove + and spaces)
        $normalizedPhone = $this->normalizePhone($phone);
        
        try {
            // Get available instance for this tenant
            $instance = $this->getAvailableInstance($tenantId);
            
            if (!$instance) {
                Log::error('No WhatsApp instances available for tenant', [
                    'tenant_id' => $tenantId,
                    'phone' => $normalizedPhone,
                ]);
                return false;
            }

            // Build Evolution API URL
            $url = rtrim($instance->getEvolutionApiBaseUrl(), '/') 
                   . '/message/sendText/' 
                   . $instance->instance_name;

            // Send message via Evolution API
            $response = Http::withHeaders([
                'apikey' => $instance->evolution_api_key,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'number' => $normalizedPhone,
                'text' => $message,
            ]);

            if ($response->successful()) {
                // Increment sent counter
                $instance->incrementSentCount();
                
                Log::info('WhatsApp message sent successfully via Evolution API', [
                    'tenant_id' => $tenantId,
                    'instance_id' => $instance->id,
                    'instance_name' => $instance->instance_name,
                    'phone' => $normalizedPhone,
                    'status' => $response->status(),
                ]);
                
                return true;
            }

            Log::warning('Evolution API returned non-success status', [
                'tenant_id' => $tenantId,
                'instance_id' => $instance->id,
                'phone' => $normalizedPhone,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message via Evolution API', [
                'tenant_id' => $tenantId,
                'phone' => $normalizedPhone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Get available WhatsApp instance for tenant with load balancing
     * Uses round-robin strategy cached per tenant
     * 
     * @param int $tenantId
     * @return TenantWhatsAppInstance|null
     */
    protected function getAvailableInstance(int $tenantId): ?TenantWhatsAppInstance
    {
        // Get all active instances with available quota
        $instances = TenantWhatsAppInstance::where('tenant_id', $tenantId)
            ->active()
            ->withAvailableQuota()
            ->orderBy('messages_sent_today', 'asc') // Prefer instances with less usage
            ->get();

        if ($instances->isEmpty()) {
            return null;
        }

        // If only one instance, return it
        if ($instances->count() === 1) {
            return $instances->first();
        }

        // Load balancing: Round-robin with cache
        $cacheKey = "whatsapp_instance_index_{$tenantId}";
        $currentIndex = Cache::get($cacheKey, 0);
        
        // Get instance at current index (with wrap around)
        $instance = $instances->get($currentIndex % $instances->count());
        
        // Increment index for next call
        Cache::put($cacheKey, ($currentIndex + 1) % $instances->count(), now()->addMinutes(60));
        
        return $instance;
    }

    /**
     * Normalize phone number to remove special characters
     * 
     * @param string $phone Phone number
     * @return string Normalized phone number (only digits, may include country code)
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove spaces, dashes, parentheses, and other non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Remove + if present (Evolution API expects pure numbers)
        $phone = str_replace('+', '', $phone);
        
        // If phone starts with 57 and has 12 digits, it's Colombian format
        if (str_starts_with($phone, '57') && strlen($phone) === 12) {
            return $phone;
        }
        
        // If phone has 10 digits (Colombian mobile without country code), add 57 prefix
        if (strlen($phone) === 10 && !str_starts_with($phone, '57')) {
            return '57' . $phone;
        }
        
        // Return as is for other formats
        return $phone;
    }

    /**
     * Send campaign message (alias for sendMessage)
     */
    public function sendCampaignMessage(string $phone, string $message, int $tenantId): bool
    {
        return $this->sendMessage($phone, $message, $tenantId);
    }

    /**
     * Send media (image, video, or document) via WhatsApp
     * 
     * @param string $phone Phone number with country code
     * @param string $mediaType Type of media: 'image', 'video', or 'document'
     * @param string $media URL or base64 encoded media
     * @param int $tenantId Tenant ID
     * @param string|null $caption Optional caption for the media
     * @param string|null $fileName Optional file name (recommended for documents)
     * @param string|null $mimeType Optional MIME type (e.g., 'image/png', 'video/mp4', 'application/pdf')
     * @return bool
     */
    public function sendMedia(
        string $phone,
        string $mediaType,
        string $media,
        int $tenantId,
        ?string $caption = null,
        ?string $fileName = null,
        ?string $mimeType = null
    ): bool {
        // Validate media type
        $validMediaTypes = ['image', 'video', 'document'];
        if (!in_array($mediaType, $validMediaTypes)) {
            Log::error('Invalid media type provided', [
                'media_type' => $mediaType,
                'valid_types' => $validMediaTypes,
            ]);
            return false;
        }

        // Normalize phone number
        $normalizedPhone = $this->normalizePhone($phone);
        
        try {
            // Get available instance for this tenant
            $instance = $this->getAvailableInstance($tenantId);
            
            if (!$instance) {
                Log::error('No WhatsApp instances available for tenant', [
                    'tenant_id' => $tenantId,
                    'phone' => $normalizedPhone,
                    'media_type' => $mediaType,
                ]);
                return false;
            }

            // Build Evolution API URL for media
            $url = rtrim($instance->getEvolutionApiBaseUrl(), '/') 
                   . '/message/sendMedia/' 
                   . $instance->instance_name;

            // Build request payload
            $payload = [
                'number' => $normalizedPhone,
                'mediatype' => $mediaType,
                'media' => $media,
            ];

            // Add optional fields
            if ($caption !== null) {
                $payload['caption'] = $caption;
            }

            if ($fileName !== null) {
                $payload['fileName'] = $fileName;
            }

            if ($mimeType !== null) {
                $payload['mimetype'] = $mimeType;
            } else {
                // Set default MIME types based on media type
                $payload['mimetype'] = $this->getDefaultMimeType($mediaType);
            }

            // Send media via Evolution API
            $response = Http::withHeaders([
                'apikey' => $instance->evolution_api_key,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                // Increment sent counter
                $instance->incrementSentCount();
                
                Log::info('WhatsApp media sent successfully via Evolution API', [
                    'tenant_id' => $tenantId,
                    'instance_id' => $instance->id,
                    'instance_name' => $instance->instance_name,
                    'phone' => $normalizedPhone,
                    'media_type' => $mediaType,
                    'has_caption' => $caption !== null,
                    'file_name' => $fileName,
                    'status' => $response->status(),
                ]);
                
                return true;
            }

            Log::warning('Evolution API returned non-success status for media', [
                'tenant_id' => $tenantId,
                'instance_id' => $instance->id,
                'phone' => $normalizedPhone,
                'media_type' => $mediaType,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp media via Evolution API', [
                'tenant_id' => $tenantId,
                'phone' => $normalizedPhone,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Get default MIME type based on media type
     * 
     * @param string $mediaType
     * @return string
     */
    protected function getDefaultMimeType(string $mediaType): string
    {
        return match ($mediaType) {
            'image' => 'image/png',
            'video' => 'video/mp4',
            'document' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /**
     * Send image via WhatsApp (convenience method)
     * 
     * @param string $phone Phone number
     * @param string $imageUrl URL or base64 encoded image
     * @param int $tenantId Tenant ID
     * @param string|null $caption Optional caption
     * @param string|null $fileName Optional file name
     * @param string|null $mimeType Optional MIME type (default: image/png)
     * @return bool
     */
    public function sendImage(
        string $phone,
        string $imageUrl,
        int $tenantId,
        ?string $caption = null,
        ?string $fileName = null,
        ?string $mimeType = null
    ): bool {
        return $this->sendMedia(
            $phone,
            'image',
            $imageUrl,
            $tenantId,
            $caption,
            $fileName,
            $mimeType ?? 'image/png'
        );
    }

    /**
     * Send video via WhatsApp (convenience method)
     * 
     * @param string $phone Phone number
     * @param string $videoUrl URL or base64 encoded video
     * @param int $tenantId Tenant ID
     * @param string|null $caption Optional caption
     * @param string|null $fileName Optional file name
     * @param string|null $mimeType Optional MIME type (default: video/mp4)
     * @return bool
     */
    public function sendVideo(
        string $phone,
        string $videoUrl,
        int $tenantId,
        ?string $caption = null,
        ?string $fileName = null,
        ?string $mimeType = null
    ): bool {
        return $this->sendMedia(
            $phone,
            'video',
            $videoUrl,
            $tenantId,
            $caption,
            $fileName,
            $mimeType ?? 'video/mp4'
        );
    }

    /**
     * Send document via WhatsApp (convenience method)
     * 
     * @param string $phone Phone number
     * @param string $documentUrl URL or base64 encoded document
     * @param int $tenantId Tenant ID
     * @param string $fileName File name (recommended for documents)
     * @param string|null $caption Optional caption
     * @param string|null $mimeType Optional MIME type (default: application/pdf)
     * @return bool
     */
    public function sendDocument(
        string $phone,
        string $documentUrl,
        int $tenantId,
        string $fileName,
        ?string $caption = null,
        ?string $mimeType = null
    ): bool {
        return $this->sendMedia(
            $phone,
            'document',
            $documentUrl,
            $tenantId,
            $caption,
            $fileName,
            $mimeType ?? 'application/pdf'
        );
    }

    /**
     * Get statistics for tenant's WhatsApp instances
     * 
     * @param int $tenantId
     * @return array
     */
    public function getTenantStatistics(int $tenantId): array
    {
        $instances = TenantWhatsAppInstance::where('tenant_id', $tenantId)
            ->get();

        $stats = [
            'total_instances' => $instances->count(),
            'active_instances' => $instances->where('is_active', true)->count(),
            'total_daily_limit' => $instances->sum('daily_message_limit'),
            'total_sent_today' => $instances->sum('messages_sent_today'),
            'total_remaining' => 0,
            'instances' => [],
        ];

        foreach ($instances as $instance) {
            $remaining = $instance->getRemainingQuota();
            $stats['total_remaining'] += $remaining;
            
            $stats['instances'][] = [
                'id' => $instance->id,
                'name' => $instance->instance_name,
                'phone' => $instance->phone_number,
                'is_active' => $instance->is_active,
                'daily_limit' => $instance->daily_message_limit,
                'sent_today' => $instance->messages_sent_today,
                'remaining' => $remaining,
                'usage_percent' => $instance->daily_message_limit > 0 
                    ? round(($instance->messages_sent_today / $instance->daily_message_limit) * 100, 2)
                    : 0,
            ];
        }

        return $stats;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    protected string $webhookUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.n8n.whatsapp_webhook_url', 'https://n8n.appcoresas.cloud/webhook/notifications/whatsapp');
    }

    /**
     * Normalize phone number to include Colombia country code (57)
     * 
     * @param string $phone Phone number
     * @return string Normalized phone number with 57 prefix
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove spaces, dashes, and other non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If phone already starts with 57 and has 12 digits, return as is
        if (str_starts_with($phone, '57') && strlen($phone) === 12) {
            return $phone;
        }
        
        // If phone has 10 digits (Colombian mobile), add 57 prefix
        if (strlen($phone) === 10) {
            return '57' . $phone;
        }
        
        // If phone starts with 57 but doesn't have 12 digits, remove 57 and re-add
        if (str_starts_with($phone, '57')) {
            $phone = substr($phone, 2);
            if (strlen($phone) === 10) {
                return '57' . $phone;
            }
        }
        
        // Return as is if format is unexpected (will be logged)
        return $phone;
    }

    /**
     * Send WhatsApp message
     * 
     * @param string $phone Phone number with country code (e.g., 573116677099)
     * @param string $message Message text (supports plain text or Markdown)
     * @param string $userToken JWT token of the authenticated user
     * @return bool
     */
    public function sendMessage(string $phone, string $message, string $userToken): bool
    {
        // Normalize phone number
        $normalizedPhone = $this->normalizePhone($phone);
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $userToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->webhookUrl, [
                'phone' => $normalizedPhone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                Log::info('WhatsApp message sent successfully', [
                    'phone' => $normalizedPhone,
                    'original_phone' => $phone,
                    'status' => $response->status()
                ]);
                return true;
            }

            Log::warning('WhatsApp webhook returned non-success status', [
                'phone' => $normalizedPhone,
                'original_phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message via webhook', [
                'phone' => $normalizedPhone,
                'original_phone' => $phone,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send campaign message (alias for sendMessage)
     */
    public function sendCampaignMessage(string $phone, string $message, string $userToken): bool
    {
        return $this->sendMessage($phone, $message, $userToken);
    }
}

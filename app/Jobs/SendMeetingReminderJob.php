<?php

namespace App\Jobs;

use App\Models\MeetingReminder;
use App\Models\TenantMessagingCredit;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

class SendMeetingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MeetingReminder $reminder
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppNotificationService $whatsappService): void
    {
        // Verify reminder is still pending
        if ($this->reminder->status !== 'pending') {
            Log::info('Meeting reminder already processed or cancelled', [
                'reminder_id' => $this->reminder->id,
                'status' => $this->reminder->status,
            ]);
            return;
        }

        // Update status to processing
        $this->reminder->update(['status' => 'processing']);

        $meeting = $this->reminder->meeting()->with('planner')->first();
        
        if (!$meeting) {
            $this->reminder->update([
                'status' => 'failed',
                'error_message' => 'Meeting not found',
            ]);
            return;
        }

        // Build message
        $message = $this->buildReminderMessage($meeting);

        $sentCount = 0;
        $failedCount = 0;
        $recipients = $this->reminder->recipients ?? [];
        $recipientCount = count($recipients);

        // Check if tenant has enough WhatsApp credits before sending
        $tenantCredit = TenantMessagingCredit::where('tenant_id', $meeting->tenant_id)->first();
        
        if (!$tenantCredit) {
            Log::error('No messaging credits found for tenant', [
                'tenant_id' => $meeting->tenant_id,
                'reminder_id' => $this->reminder->id,
            ]);
            
            $this->reminder->update([
                'status' => 'failed',
                'error_message' => 'No messaging credits configured for tenant',
            ]);
            return;
        }

        if (!$tenantCredit->hasWhatsAppCredits($recipientCount)) {
            Log::warning('Insufficient WhatsApp credits', [
                'tenant_id' => $meeting->tenant_id,
                'reminder_id' => $this->reminder->id,
                'required' => $recipientCount,
                'available' => $tenantCredit->whatsapp_available,
            ]);
            
            $this->reminder->update([
                'status' => 'failed',
                'error_message' => "Créditos insuficientes. Disponibles: {$tenantCredit->whatsapp_available}, Requeridos: {$recipientCount}",
            ]);
            return;
        }

        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? null;
            
            if (!$phone) {
                $failedCount++;
                Log::warning('Recipient without phone number', [
                    'reminder_id' => $this->reminder->id,
                    'recipient' => $recipient,
                ]);
                continue;
            }

            try {
                // Send WhatsApp via n8n webhook
                $success = $this->sendWhatsAppViaWebhook($phone, $message);
                
                if ($success) {
                    $sentCount++;
                    
                    // Consume WhatsApp credit for successful send
                    $tenantCredit->consumeWhatsApp(1, "Meeting reminder #{$this->reminder->id} to {$phone}");
                    
                    Log::info('Meeting reminder sent successfully', [
                        'reminder_id' => $this->reminder->id,
                        'recipient_name' => $recipient['name'] ?? 'Unknown',
                        'phone' => $phone,
                    ]);
                } else {
                    $failedCount++;
                    Log::warning('Failed to send meeting reminder', [
                        'reminder_id' => $this->reminder->id,
                        'recipient_name' => $recipient['name'] ?? 'Unknown',
                        'phone' => $phone,
                    ]);
                }
            } catch (\Exception $e) {
                $failedCount++;
                Log::error('Exception sending meeting reminder', [
                    'reminder_id' => $this->reminder->id,
                    'recipient_name' => $recipient['name'] ?? 'Unknown',
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }

            // Rate limiting: wait 500ms between messages
            usleep(500000);
        }

        // Update reminder with results
        $this->reminder->update([
            'status' => $failedCount === count($recipients) ? 'failed' : 'sent',
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'sent_at' => now(),
            'error_message' => $failedCount === count($recipients) ? 'All messages failed' : null,
        ]);
    }

    /**
     * Send WhatsApp message via n8n webhook
     */
    protected function sendWhatsAppViaWebhook(string $phone, string $message): bool
    {
        try {
            $webhookUrl = config('services.n8n.whatsapp_webhook_url');
            
            if (!$webhookUrl) {
                Log::error('N8N WhatsApp webhook URL not configured');
                return false;
            }

            // Get superadmin user (ID 1) for authentication
            $adminUser = \App\Models\User::find(1);
            
            if (!$adminUser) {
                Log::error('Superadmin user (ID 1) not found for WhatsApp webhook authentication');
                return false;
            }

            // Generate JWT token for superadmin
            $token = JWTAuth::fromUser($adminUser);
            
            if (!$token) {
                Log::error('Failed to generate JWT token for superadmin user', [
                    'user_id' => $adminUser->id
                ]);
                return false;
            }

            // Normalize phone number
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) === 10) {
                $phone = '57' . $phone; // Add Colombia country code
            }

            $payload = [
                'phone' => $phone,
                'message' => $message,
            ];

            Log::info('=== SENDING WHATSAPP VIA N8N WEBHOOK ===', [
                'webhook_url' => $webhookUrl,
                'payload' => $payload,
                'phone_original' => $phone,
                'has_token' => !empty($token),
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($webhookUrl, $payload);

            Log::info('=== WHATSAPP WEBHOOK RESPONSE ===', [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                Log::info('WhatsApp reminder sent via n8n webhook', [
                    'phone' => $phone,
                    'status' => $response->status()
                ]);
                return true;
            }

            Log::warning('N8N webhook returned non-success status', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp via n8n webhook', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build the reminder message
     */
    protected function buildReminderMessage($meeting): string
    {
        // Use custom message if provided, otherwise use default template
        if ($this->reminder->message) {
            return $this->reminder->message;
        }

        // starts_at ya está en America/Bogota por APP_TIMEZONE
        $startsAt = \Carbon\Carbon::parse($meeting->starts_at);
        
        $message = "🔔 *Recordatorio de Reunión*\n\n";
        $message .= "📋 *Título:* {$meeting->title}\n";
        $message .= "📅 *Fecha:* {$startsAt->format('d/m/Y')}\n";
        $message .= "🕐 *Hora:* {$startsAt->format('h:i A')}\n";
        
        if ($meeting->lugar_nombre) {
            $message .= "📍 *Lugar:* {$meeting->lugar_nombre}\n";
        }
        
        if ($meeting->description) {
            $message .= "\n📝 *Descripción:*\n{$meeting->description}\n";
        }
        
        $message .= "\n¡No olvides asistir!";
        
        return $message;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->reminder->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}

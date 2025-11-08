<?php

namespace App\Jobs;

use App\Models\MeetingReminder;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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

        $meeting = $this->reminder->meeting()->with('user')->first();
        
        if (!$meeting) {
            $this->reminder->update([
                'status' => 'failed',
                'error_message' => 'Meeting not found',
            ]);
            return;
        }

        // Get creator's token for WhatsApp sending
        $creatorToken = $meeting->user->whatsapp_token ?? $this->reminder->createdBy->whatsapp_token ?? null;
        
        if (!$creatorToken) {
            Log::error('No creator token available for WhatsApp sending', [
                'reminder_id' => $this->reminder->id,
                'meeting_id' => $meeting->id,
            ]);
            
            $this->reminder->update([
                'status' => 'failed',
                'error_message' => 'No WhatsApp token available',
            ]);
            return;
        }

        // Build message
        $message = $this->buildReminderMessage($meeting);

        $sentCount = 0;
        $failedCount = 0;
        $recipients = $this->reminder->recipients ?? [];

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
                $success = $whatsappService->sendMessage($phone, $message, $creatorToken);
                
                if ($success) {
                    $sentCount++;
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
     * Build reminder message
     */
    protected function buildReminderMessage($meeting): string
    {
        // Use custom message if provided, otherwise use default template
        if ($this->reminder->message) {
            return $this->reminder->message;
        }

        $startsAt = Carbon::parse($meeting->starts_at);
        
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

<?php

namespace App\Services;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MeetingAttendee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampaignService
{
    public function createCampaign(array $data): Campaign
    {
        return DB::transaction(function () use ($data) {
            $user = request()->user();
            
            // Determine status based on scheduled_at
            $status = !empty($data['scheduled_at']) ? 'scheduled' : 'pending';
            
            // Generate a long-lived JWT token (1 year) for external service authentication
            // Store original TTL, set to 1 year, generate token, then restore
            $originalTTL = config('jwt.ttl');
            config(['jwt.ttl' => 525600]); // 1 year
            $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
            config(['jwt.ttl' => $originalTTL]); // Restore original TTL
            
            $campaign = Campaign::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'creator_token' => $token,
                'title' => $data['title'],
                'message' => $data['message'],
                'channel' => $data['channel'],
                'filter_json' => $data['filter_json'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => $status,
            ]);

            $recipients = $this->generateRecipients($campaign);
            $campaign->update(['total_recipients' => count($recipients)]);

            if (!empty($data['scheduled_at'])) {
                // Schedule for later - parse date in Colombia timezone and use directly
                $scheduledDate = \Carbon\Carbon::parse($data['scheduled_at'], config('app.timezone'));
                
                SendCampaignJob::dispatch($campaign)
                    ->delay($scheduledDate);
            } else {
                // Send immediately
                SendCampaignJob::dispatch($campaign);
            }

            return $campaign->fresh();
        });
    }

    protected function generateRecipients(Campaign $campaign): array
    {
        $recipients = [];
        $filters = $campaign->filter_json ?? [];
        $target = $filters['target'] ?? 'all_users';

        // 1. Todos los usuarios del tenant
        if ($target === 'all_users') {
            $users = User::where('tenant_id', $campaign->tenant_id)->get();
            $recipients = array_merge($recipients, $this->extractRecipientsFromUsers($campaign, $users));
        }

        // 2. Asistentes de reunión(es) específica(s)
        if ($target === 'meeting_attendees' && isset($filters['meeting_ids'])) {
            $meetingIds = is_array($filters['meeting_ids']) ? $filters['meeting_ids'] : [$filters['meeting_ids']];
            $attendees = MeetingAttendee::whereIn('meeting_id', $meetingIds)->get();
            $recipients = array_merge($recipients, $this->extractRecipientsFromAttendees($campaign, $attendees));
        }

        // 3. Lista personalizada de emails/teléfonos
        if ($target === 'custom_list' && isset($filters['custom_recipients'])) {
            $recipients = array_merge($recipients, $this->extractCustomRecipients($campaign, $filters['custom_recipients']));
        }

        // Eliminar duplicados por recipient_value
        $recipients = $this->deduplicateRecipients($recipients);

        if (!empty($recipients)) {
            CampaignRecipient::insert($recipients);
        }

        return $recipients;
    }

    protected function extractRecipientsFromUsers(Campaign $campaign, $users): array
    {
        $recipients = [];
        
        foreach ($users as $user) {
            if (in_array($campaign->channel, ['email', 'both']) && $user->email) {
                $recipients[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'email',
                    'recipient_value' => $user->email,
                    'recipient_name' => $user->name,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            if (in_array($campaign->channel, ['whatsapp', 'both']) && $user->phone) {
                $recipients[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'whatsapp',
                    'recipient_value' => $user->phone,
                    'recipient_name' => $user->name,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        
        return $recipients;
    }

    protected function extractRecipientsFromAttendees(Campaign $campaign, $attendees): array
    {
        $recipients = [];
        
        foreach ($attendees as $attendee) {
            if (in_array($campaign->channel, ['email', 'both']) && $attendee->email) {
                $recipients[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'email',
                    'recipient_value' => $attendee->email,
                    'recipient_name' => $attendee->nombre,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            if (in_array($campaign->channel, ['whatsapp', 'both']) && $attendee->telefono) {
                $recipients[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'whatsapp',
                    'recipient_value' => $attendee->telefono,
                    'recipient_name' => $attendee->nombre,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        
        return $recipients;
    }

    protected function extractCustomRecipients(Campaign $campaign, array $customRecipients): array
    {
        $recipients = [];
        
        foreach ($customRecipients as $custom) {
            $type = $custom['type']; // 'email' or 'phone'
            $value = $custom['value'];
            $name = $custom['name'] ?? null;
            
            // Validar que el tipo coincida con el canal
            if ($type === 'email' && !in_array($campaign->channel, ['email', 'both'])) {
                continue;
            }
            
            if ($type === 'phone' && !in_array($campaign->channel, ['whatsapp', 'both'])) {
                continue;
            }
            
            $recipients[] = [
                'campaign_id' => $campaign->id,
                'recipient_type' => $type === 'phone' ? 'whatsapp' : 'email',
                'recipient_value' => $value,
                'recipient_name' => $name,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        return $recipients;
    }

    protected function deduplicateRecipients(array $recipients): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($recipients as $recipient) {
            $key = $recipient['recipient_type'] . ':' . $recipient['recipient_value'];
            
            if (!isset($seen[$key])) {
                $unique[] = $recipient;
                $seen[$key] = true;
            }
        }
        
        return $unique;
    }

    public function sendToRecipient(CampaignRecipient $recipient): bool
    {
        try {
            if ($recipient->recipient_type === 'email') {
                $campaign = $recipient->campaign;
                
                // Use the long-lived token stored with the campaign
                $token = $campaign->creator_token;
                
                if (!$token) {
                    Log::error('No creator token available for email sending', [
                        'campaign_id' => $campaign->id,
                        'recipient' => $recipient->recipient_value
                    ]);
                    throw new \Exception('No authentication token available');
                }
                
                $emailService = app(EmailNotificationService::class);
                $success = $emailService->sendEmail(
                    $recipient->recipient_value,
                    $campaign->message,
                    $token
                );
                
                if (!$success) {
                    throw new \Exception('Email service returned false');
                }
                
            } elseif ($recipient->recipient_type === 'whatsapp') {
                $campaign = $recipient->campaign;
                
                // Use the long-lived token stored with the campaign
                $token = $campaign->creator_token;
                
                if (!$token) {
                    Log::error('No creator token available for WhatsApp sending', [
                        'campaign_id' => $campaign->id,
                        'recipient' => $recipient->recipient_value
                    ]);
                    throw new \Exception('No authentication token available');
                }
                
                $whatsappService = app(WhatsAppNotificationService::class);
                $success = $whatsappService->sendMessage(
                    $recipient->recipient_value,
                    $campaign->message,
                    $token
                );
                
                if (!$success) {
                    throw new \Exception('WhatsApp service returned false');
                }
            }

            $recipient->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send to recipient', [
                'recipient_id' => $recipient->id,
                'type' => $recipient->recipient_type,
                'value' => $recipient->recipient_value,
                'error' => $e->getMessage()
            ]);
            
            $recipient->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

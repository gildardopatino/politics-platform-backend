<?php

namespace App\Services;

use App\Jobs\Campaigns\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MeetingAttendee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function createCampaign(array $data): Campaign
    {
        return DB::transaction(function () use ($data) {
            $user = request()->user();
            
            $campaign = Campaign::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'title' => $data['title'],
                'message' => $data['message'],
                'channel' => $data['channel'],
                'filter_json' => $data['filter_json'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => 'pending',
            ]);

            $recipients = $this->generateRecipients($campaign);
            $campaign->update(['total_recipients' => count($recipients)]);

            if (!empty($data['scheduled_at'])) {
                // Schedule for later
                SendCampaignJob::dispatch($campaign)
                    ->delay(now()->parse($data['scheduled_at']));
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
            
            if (in_array($campaign->channel, ['whatsapp', 'both']) && $user->telefono) {
                $recipients[] = [
                    'campaign_id' => $campaign->id,
                    'recipient_type' => 'whatsapp',
                    'recipient_value' => $user->telefono,
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
                // TODO: Implement email sending logic
                // Mail::to($recipient->recipient_value)->send(new CampaignMail($recipient->campaign));
            } elseif ($recipient->recipient_type === 'whatsapp') {
                $whatsappService = app(\App\Services\WhatsApp\WhatsAppInterface::class);
                $whatsappService->send($recipient->recipient_value, $recipient->campaign->message);
            }

            $recipient->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            $recipient->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

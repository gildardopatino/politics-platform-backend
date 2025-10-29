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
            $campaign = Campaign::create([
                'tenant_id' => app('tenant')->id,
                'created_by_user_id' => auth()->id(),
                'titulo' => $data['titulo'],
                'mensaje' => $data['mensaje'],
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

        if (isset($filters['target']) && $filters['target'] === 'all_users') {
            $users = User::where('tenant_id', $campaign->tenant_id)->get();
            
            foreach ($users as $user) {
                if (in_array($campaign->channel, ['email', 'both']) && $user->email) {
                    $recipients[] = [
                        'campaign_id' => $campaign->id,
                        'recipient_type' => 'email',
                        'recipient_value' => $user->email,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                
                if (in_array($campaign->channel, ['sms', 'both']) && $user->telefono) {
                    $recipients[] = [
                        'campaign_id' => $campaign->id,
                        'recipient_type' => 'sms',
                        'recipient_value' => $user->telefono,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if (isset($filters['target']) && $filters['target'] === 'meeting_attendees' && isset($filters['meeting_id'])) {
            $attendees = MeetingAttendee::where('meeting_id', $filters['meeting_id'])->get();
            
            foreach ($attendees as $attendee) {
                if (in_array($campaign->channel, ['email', 'both']) && $attendee->email) {
                    $recipients[] = [
                        'campaign_id' => $campaign->id,
                        'recipient_type' => 'email',
                        'recipient_value' => $attendee->email,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                
                if (in_array($campaign->channel, ['sms', 'both']) && $attendee->telefono) {
                    $recipients[] = [
                        'campaign_id' => $campaign->id,
                        'recipient_type' => 'sms',
                        'recipient_value' => $attendee->telefono,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if (!empty($recipients)) {
            CampaignRecipient::insert($recipients);
        }

        return $recipients;
    }

    public function sendToRecipient(CampaignRecipient $recipient): bool
    {
        try {
            if ($recipient->recipient_type === 'email') {
                // TODO: Implement email sending logic
                // Mail::to($recipient->recipient_value)->send(new CampaignMail($recipient->campaign));
            } elseif ($recipient->recipient_type === 'sms') {
                $smsService = app(\App\Services\SMS\SMSInterface::class);
                $smsService->send($recipient->recipient_value, $recipient->campaign->mensaje);
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

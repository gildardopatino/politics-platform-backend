<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingReminderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meeting_id' => $this->meeting_id,
            'reminder_datetime' => $this->reminder_datetime?->toISOString(),
            'message' => $this->message,
            'recipients' => $this->recipients,
            'status' => $this->status,
            'sent_at' => $this->sent_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'job_id' => $this->job_id,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

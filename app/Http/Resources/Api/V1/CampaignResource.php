<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
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
            'tenant_id' => $this->tenant_id,
            'title' => $this->title,
            'message' => $this->message,
            'channel' => $this->channel,
            'filter_json' => $this->filter_json,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'status' => $this->status,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'progress_percentage' => $this->getProgressPercentage(),
            
            // Relación con usuario creador
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy', fn() => new UserResource($this->createdBy)),
            
            // Colección de destinatarios
            'recipients' => $this->whenLoaded('recipients', fn() => CampaignRecipientResource::collection($this->recipients)),
            'recipients_count' => $this->whenCounted('recipients'),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}

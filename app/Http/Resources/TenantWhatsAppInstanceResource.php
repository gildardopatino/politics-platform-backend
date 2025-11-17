<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantWhatsAppInstanceResource extends JsonResource
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
            'phone_number' => $this->phone_number,
            'instance_name' => $this->instance_name,
            'evolution_api_url' => $this->evolution_api_url,
            'daily_message_limit' => $this->daily_message_limit,
            'messages_sent_today' => $this->messages_sent_today,
            'remaining_quota' => $this->getRemainingQuota(),
            'last_reset_date' => $this->last_reset_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'can_send_messages' => $this->canSendMessage(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Include API key only on specific requests (show/edit)
            'evolution_api_key' => $this->when(
                $request->routeIs('*.show') || $request->routeIs('*.edit'),
                $this->evolution_api_key
            ),
            
            // Include tenant relationship if loaded
            'tenant' => $this->whenLoaded('tenant', function () {
                return [
                    'id' => $this->tenant->id,
                    'slug' => $this->tenant->slug,
                    'nombre' => $this->tenant->nombre,
                ];
            }),
        ];
    }
}

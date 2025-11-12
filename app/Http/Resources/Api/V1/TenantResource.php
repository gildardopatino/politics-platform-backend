<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
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
            'slug' => $this->slug,
            'nombre' => $this->nombre,
            'tipo_cargo' => $this->tipo_cargo,
            'identificacion' => $this->identificacion,
            'metadata' => $this->metadata,
            
            // Expiration information
            'start_date' => $this->start_date?->toISOString(),
            'expiration_date' => $this->expiration_date?->toISOString(),
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'is_not_started' => $this->isNotStarted(),
            'days_until_expiration' => $this->daysUntilExpiration(),
            
            'users_count' => $this->whenCounted('users'),
            'meetings_count' => $this->whenCounted('meetings'),
            'campaigns_count' => $this->whenCounted('campaigns'),
            
            // Messaging credits information
            'messaging_credits' => $this->when(
                $this->relationLoaded('messagingCredit') && $this->messagingCredit,
                function () {
                    $summary = $this->messagingCredit->getSummary();
                    return [
                        'emails' => [
                            'available' => $summary['emails']['available'],
                            'used' => $summary['emails']['used'],
                            'total_cost' => $summary['emails']['total_cost'],
                            'unit_price' => $summary['emails']['unit_price'],
                            'percentage_used' => $summary['emails']['used'] > 0 
                                ? round(($summary['emails']['used'] / ($summary['emails']['available'] + $summary['emails']['used'])) * 100, 2)
                                : 0,
                        ],
                        'whatsapp' => [
                            'available' => $summary['whatsapp']['available'],
                            'used' => $summary['whatsapp']['used'],
                            'total_cost' => $summary['whatsapp']['total_cost'],
                            'unit_price' => $summary['whatsapp']['unit_price'],
                            'percentage_used' => $summary['whatsapp']['used'] > 0
                                ? round(($summary['whatsapp']['used'] / ($summary['whatsapp']['available'] + $summary['whatsapp']['used'])) * 100, 2)
                                : 0,
                        ],
                        'total_cost' => $summary['total_cost'],
                        'currency' => 'COP',
                        'last_transaction_at' => $this->messagingCredit->updated_at?->toISOString(),
                    ];
                }
            ),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

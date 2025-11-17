<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceAllocationResource extends JsonResource
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
            
            // Sistema legacy
            'type' => $this->type,
            'amount' => $this->amount,
            'descripcion' => $this->descripcion,
            'fecha_asignacion' => $this->fecha_asignacion,
            'details' => $this->details,
            
            // Sistema nuevo
            'title' => $this->title,
            'meeting_id' => $this->meeting_id,
            'meeting' => $this->whenLoaded('meeting', function() {
                return [
                    'id' => $this->meeting->id,
                    'title' => $this->meeting->title ?? 'Sin título',
                ];
            }),
            'allocation_date' => $this->allocation_date?->toDateString(),
            'notes' => $this->notes,
            'cash_purpose' => $this->cash_purpose,
            'status' => $this->status,
            'total_cost' => $this->total_cost,
            
            // Items del nuevo sistema
            'items' => ResourceAllocationItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->when($this->relationLoaded('items'), fn() => $this->items->count()),
            
            // Relación con usuarios
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assigned_to' => $this->whenLoaded('assignedTo', fn() => new UserResource($this->assignedTo)),
            
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'assigned_by' => $this->whenLoaded('assignedBy', fn() => new UserResource($this->assignedBy)),
            
            'leader_user_id' => $this->leader_user_id,
            'leader' => $this->whenLoaded('leader', fn() => new UserResource($this->leader)),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}

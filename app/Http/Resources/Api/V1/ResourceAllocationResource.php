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
            'type' => $this->type,
            'amount' => $this->amount,
            'details' => $this->details,
            'allocation_date' => $this->allocation_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            
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

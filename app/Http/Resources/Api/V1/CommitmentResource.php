<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommitmentResource extends JsonResource
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
            'meeting_id' => $this->meeting_id,
            'description' => $this->description,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            
            // Relaciones con usuarios
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => new UserResource($this->assignedUser)),
            
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy', fn() => new UserResource($this->createdBy)),
            
            // Relación con reunión
            'meeting' => $this->whenLoaded('meeting', fn() => new MeetingResource($this->meeting)),
            
            // Relación con prioridad
            'priority_id' => $this->priority_id,
            'priority' => $this->whenLoaded('priority', fn() => new PriorityResource($this->priority)),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceAllocationItemResource extends JsonResource
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
            'resource_allocation_id' => $this->resource_allocation_id,
            'resource_item_id' => $this->resource_item_id,
            
            // Información del item del catálogo
            'resource_item' => $this->whenLoaded('resourceItem', function() {
                return [
                    'id' => $this->resourceItem->id,
                    'name' => $this->resourceItem->name,
                    'description' => $this->resourceItem->description,
                    'category' => $this->resourceItem->category,
                    'unit' => $this->resourceItem->unit,
                ];
            }),
            
            // Cantidades y costos
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'subtotal' => $this->subtotal,
            
            // Detalles
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            
            // Estado y seguimiento
            'status' => $this->status,
            'delivered_at' => $this->delivered_at?->toISOString(),
            'returned_at' => $this->returned_at?->toISOString(),
            'delivered_by_user_id' => $this->delivered_by_user_id,
            'returned_to_user_id' => $this->returned_to_user_id,
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

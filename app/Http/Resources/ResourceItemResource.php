<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceItemResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'unit' => $this->unit,
            'unit_cost' => $this->unit_cost,
            'currency' => $this->currency,
            'stock_quantity' => $this->stock_quantity,
            'min_stock' => $this->min_stock,
            'supplier' => $this->supplier,
            'supplier_contact' => $this->supplier_contact,
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,
            'is_low_stock' => $this->is_low_stock,
            'formatted_cost' => $this->formatted_cost,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

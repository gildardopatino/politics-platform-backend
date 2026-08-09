<?php

namespace Database\Factories;

use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use App\Models\ResourceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResourceAllocationItem>
 */
class ResourceAllocationItemFactory extends Factory
{
    protected $model = ResourceAllocationItem::class;

    public function definition(): array
    {
        return [
            'resource_allocation_id' => ResourceAllocation::factory(),
            'resource_item_id' => ResourceItem::factory(),
            'quantity' => 10,
            'unit_cost' => 15000,
            'status' => 'pending',
        ];
    }

    public function de(ResourceAllocation $asignacion, ResourceItem $recurso, float $cantidad = 10): static
    {
        return $this->state(fn () => [
            'resource_allocation_id' => $asignacion->id,
            'resource_item_id' => $recurso->id,
            'quantity' => $cantidad,
            'unit_cost' => $recurso->unit_cost,
        ]);
    }

    public function conEstado(string $estado): static
    {
        return $this->state(fn () => ['status' => $estado]);
    }
}

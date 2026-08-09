<?php

namespace Database\Factories;

use App\Models\ResourceItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResourceItem>
 */
class ResourceItemFactory extends Factory
{
    protected $model = ResourceItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Silla plástica', 'Mesa plegable', 'Carpa 3x3', 'Sonido básico']),
            'description' => fake()->sentence(),
            'category' => 'furniture',
            'unit' => 'unidad',
            'unit_cost' => 15000,
            'currency' => 'COP',
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'min_stock' => 10,
            'is_active' => true,
            'is_inventory_tracked' => true,
        ];
    }

    /**
     * Efectivo: se gasta, no se devuelve, y por eso no lleva inventario.
     */
    public function cash(): static
    {
        return $this->state(fn () => [
            'name' => 'Efectivo para gastos',
            'category' => 'cash',
            'unit' => 'COP',
            'unit_cost' => 1,
            'stock_quantity' => null,
            'min_stock' => null,
            'is_inventory_tracked' => false,
        ]);
    }

    /** Un servicio que se contrata: no hay unidades que contar. */
    public function noRastreable(): static
    {
        return $this->state(fn () => [
            'category' => 'service',
            'is_inventory_tracked' => false,
            'stock_quantity' => null,
            'min_stock' => null,
        ]);
    }

    public function conStock(int $stock, int $reservado = 0, ?int $minimo = null): static
    {
        return $this->state(fn () => [
            'stock_quantity' => $stock,
            'reserved_quantity' => $reservado,
            'min_stock' => $minimo ?? 0,
        ]);
    }
}

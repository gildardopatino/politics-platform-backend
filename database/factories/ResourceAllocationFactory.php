<?php

namespace Database\Factories;

use App\Models\ResourceAllocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResourceAllocation>
 */
class ResourceAllocationFactory extends Factory
{
    protected $model = ResourceAllocation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'assigned_to_user_id' => User::factory(),
            'assigned_by_user_id' => User::factory(),
            'leader_user_id' => User::factory(),
            'title' => 'Montaje de la reunión',
            'type' => 'material',
            'allocation_date' => now()->toDateString(),
            'status' => 'pending',
        ];
    }

    /** Todos los usuarios y el tenant del mismo lado, que es el caso normal. */
    public function paraTenant(Tenant $tenant, ?User $usuario = null): static
    {
        $usuario ??= User::factory()->forTenant($tenant)->create();

        return $this->state(fn () => [
            'tenant_id' => $tenant->id,
            'assigned_to_user_id' => $usuario->id,
            'assigned_by_user_id' => $usuario->id,
            'leader_user_id' => $usuario->id,
        ]);
    }

    public function conEstado(string $estado): static
    {
        return $this->state(fn () => ['status' => $estado]);
    }

    /** Entrega de dinero: sin ítems de inventario, con monto y propósito. */
    public function efectivo(float $monto = 200000, string $proposito = 'Refrigerios y transporte'): static
    {
        return $this->state(fn () => [
            'type' => 'cash',
            'amount' => $monto,
            'cash_purpose' => $proposito,
            'title' => 'Efectivo para la jornada',
        ]);
    }
}

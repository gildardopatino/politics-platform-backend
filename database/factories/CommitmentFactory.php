<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Commitment>
 */
class CommitmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Reunión y autor viven en el mismo tenant que el compromiso.
            'meeting_id' => fn (array $attributes) => Meeting::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'created_by' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'assigned_user_id' => null,
            'priority_id' => null,
            'description' => fake()->sentence(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'pending',
            'notes' => null,
        ];
    }

    /**
     * Compromiso perteneciente a un tenant concreto.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Vencido: due_date en el pasado y estado que el scope overdue() considera.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => now()->subWeek()->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}

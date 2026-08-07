<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // El autor vive en el mismo tenant que la campaña.
            'created_by' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => fake()->sentence(3),
            'message' => fake()->paragraph(),
            'channel' => 'whatsapp',
            'filter_json' => null,
            'status' => 'pending',
            'total_recipients' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ];
    }

    /**
     * Campaña perteneciente a un tenant concreto.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Estado concreto de la campaña. Los válidos son los del enum:
     * draft, pending, scheduled, sending, sent, failed.
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Programada para el futuro (el job va con delay).
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    /**
     * Ya enviada.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);
    }
}

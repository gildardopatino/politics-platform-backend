<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Call>
 */
class CallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Votante y operador viven en el mismo tenant que la llamada.
            'voter_id' => fn (array $attributes) => Voter::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'survey_id' => null,
            'call_date' => now(),
            'duration_seconds' => 120,
            // La columna es un enum de SEIS valores; `pending` no está entre
            // ellos aunque el controlador lo acepte (ver known-issues).
            'status' => 'completed',
            'notes' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}

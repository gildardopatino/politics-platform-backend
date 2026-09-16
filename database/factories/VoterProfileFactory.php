<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoterProfile>
 */
class VoterProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'voter_id' => Voter::factory(),
            'busca_empleo' => true,
            'disponibilidad' => 'inmediata',
            'nivel_educativo' => 'bachillerato',
            'anios_experiencia' => fake()->numberBetween(0, 20),
            'notas' => null,
            'autoriza_tratamiento_datos' => false,
        ];
    }

    /**
     * Perfil del votante dado, heredando su tenant.
     */
    public function paraVotante(Voter $voter): static
    {
        return $this->state(fn () => [
            'voter_id' => $voter->id,
            'tenant_id' => $voter->tenant_id,
        ]);
    }
}

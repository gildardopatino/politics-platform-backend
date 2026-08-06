<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TipoVotante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Voter>
 */
class VoterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cedula' => fake()->unique()->numerify('##########'),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('3#########'),
            'direccion' => fake()->streetAddress(),
            // La columna es NOT NULL con FK a tipo_votante; en pruebas no corre
            // TipoVotanteSeeder, así que se garantiza el "Elector" por defecto.
            'tipo_votante_id' => fn () => TipoVotante::firstOrCreate(['descripcion' => 'Elector'])->id,
        ];
    }

    /**
     * Votante perteneciente a un tenant concreto.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }
}

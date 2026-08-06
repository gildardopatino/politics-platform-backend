<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'nombre' => fake()->name(),
            'tipo_cargo' => fake()->randomElement([
                'Gobernacion', 'Alcaldia', 'Concejo', 'Congresista', 'Diputado', 'Otro',
            ]),
            'identificacion' => fake()->unique()->numerify('##########'),
            'email_contacto' => fake()->unique()->safeEmail(),
            'phone_contacto' => fake()->numerify('3#########'),
            // Vigencia activa: CheckTenantExpiration deja pasar la petición.
            'start_date' => now()->subMonth(),
            'expiration_date' => now()->addYear(),
        ];
    }

    /**
     * Tenant cuya vigencia ya venció (CheckTenantExpiration responde 403).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => now()->subYear(),
            'expiration_date' => now()->subDay(),
        ]);
    }
}

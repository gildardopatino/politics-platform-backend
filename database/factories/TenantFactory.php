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
    /** El cargo por defecto: uninominal, el caso de referencia (Spec 0083). */
    public const CARGO_POR_DEFECTO = 'Alcaldia';

    /** Uno de corporación, para quien necesite la otra rama. */
    public const CARGO_CORPORACION = 'Concejo';

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'nombre' => fake()->name(),
            // **Fijo, no aleatorio** (Spec 0083 · RF-0). Elegirlo al azar hacía
            // que la suite dependiera del dado: desde la 0082 el cargo decide
            // qué pide «mi candidato» —un número suelto o el par (lista,
            // preferente)—, así que un tenant que unas veces salía Alcaldía y
            // otras Concejo hacía fallar la misma prueba una de cada dos veces.
            // Se vio como una intermitencia sin explicación al cerrar la 0082-A.
            //
            // Quien necesite otro cargo lo pide: `->corporacion()` o
            // `->tipoCargo(...)`. Un test que depende del cargo tiene que
            // decirlo, y así se lee en el propio test.
            'tipo_cargo' => self::CARGO_POR_DEFECTO,
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

    /**
     * Campaña a una corporación: concejo, asamblea o senado (Spec 0067).
     *
     * Su candidato es una persona **dentro de una lista**, así que «mi
     * candidato» pide el par `(lista, preferente)` y el cruce cuenta esa fila.
     */
    public function corporacion(string $cargo = self::CARGO_CORPORACION): static
    {
        return $this->state(fn (array $attributes) => ['tipo_cargo' => $cargo]);
    }

    /**
     * Un cargo concreto, cuando la prueba depende de cuál es.
     */
    public function tipoCargo(string $cargo): static
    {
        return $this->state(fn (array $attributes) => ['tipo_cargo' => $cargo]);
    }
}
